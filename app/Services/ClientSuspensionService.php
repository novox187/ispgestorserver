<?php

namespace App\Services;

use App\Models\Audit;
use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\ClientServiceInterruption;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientSuspensionService
{
    public function __construct(
        private readonly MikroTikService $mikrotik,
        private readonly ClientWhitelistService $whitelist,
        private readonly ChatService $chat,
    ) {}

    /** Nombre de la address-list de morosos (centralizado en config/billing.php). */
    private function morososList(): string
    {
        return (string) config('billing.morosos_list', 'morosos');
    }

    /**
     * Serializa las operaciones de servicio sobre un mismo cliente.
     *
     * `withoutOverlapping()` protege al job de sí mismo, pero no impedía que la
     * corrida automática y una acción del operador cayeran sobre el mismo
     * cliente a la vez: la última escritura ganaba y quedaban dos entradas de
     * auditoría contradictorias con segundos de diferencia. El lock es por
     * cliente, así que no serializa la corrida entera.
     *
     * Si el lock no se obtiene en 10 s se ejecuta igualmente: bloquear el corte
     * indefinidamente sería peor que la carrera que se quiere evitar, y el
     * estado final converge en la siguiente corrida.
     */
    private function withClientLock(Client $client, callable $callback): mixed
    {
        $lock = \Illuminate\Support\Facades\Cache::lock("client-service-op:{$client->id}", 30);

        try {
            $lock->block(10);
        } catch (\Throwable $e) {
            Log::warning("ClientSuspensionService: no se pudo obtener el lock del cliente {$client->id}; se continúa sin él.");
            return $callback();
        }

        try {
            // Otro proceso pudo cambiar el estado mientras esperábamos el lock.
            $client->refresh();
            return $callback();
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Comprueba que la IP que vamos a bloquear siga siendo del cliente.
     *
     * La address-list opera sobre direcciones, no sobre clientes: si `clients.ip`
     * quedó desfasada (reasignación, DHCP dinámico) el corte cae sobre el abonado
     * que hoy tiene esa IP. La cola simple del cliente en el router es la fuente
     * de verdad de esa correspondencia, así que se contrasta contra ella.
     *
     * Devuelve la IP de la cola cuando NO coincide con la del cliente; null
     * cuando coincide, cuando no hay cola con la que contrastar, o cuando el
     * router no responde (en esos casos no hay evidencia de conflicto y se
     * mantiene el comportamiento anterior).
     */
    private function conflictingQueueIp(Client $client): ?string
    {
        if (!$client->ip) {
            return null;
        }

        try {
            $queueIp = app(MikroTikQueueSyncService::class)->clientQueueTargetIp($client);
        } catch (\Throwable $e) {
            Log::warning("ClientSuspensionService: no se pudo verificar la titularidad de la IP del cliente {$client->id}: " . $e->getMessage());
            return null;
        }

        if ($queueIp === null || $queueIp === $client->ip) {
            return null;
        }

        return $queueIp;
    }

    /**
     * Verifica que el corte sea efectivo en la red, no solo en la lista.
     * Nunca lanza: es una comprobación informativa que no debe tumbar el corte.
     */
    private function verifyEnforcement(Client $client): string
    {
        try {
            $check = $this->mikrotik->verifyAddressListEnforcement($client->ip, $this->morososList());

            return match ($check['state'] ?? 'unverifiable') {
                'enforced'       => ClientServiceInterruption::ENFORCEMENT_ENFORCED,
                'no_filter_rule' => ClientServiceInterruption::ENFORCEMENT_NO_FILTER_RULE,
                'entry_missing'  => ClientServiceInterruption::ENFORCEMENT_ENTRY_MISSING,
                default          => ClientServiceInterruption::ENFORCEMENT_UNVERIFIABLE,
            };
        } catch (\Throwable $e) {
            Log::warning("ClientSuspensionService: no se pudo verificar la efectividad del corte del cliente {$client->id}: " . $e->getMessage());
            return ClientServiceInterruption::ENFORCEMENT_UNVERIFIABLE;
        }
    }

    /**
     * Avisa al abonado en su portal. Best-effort por diseño: el corte ya está
     * aplicado y un fallo del canal no debe revertirlo ni propagarse.
     */
    private function notifyClient(Client $client, string $eventType, string $text, array $metadata = []): void
    {
        $this->chat->createSystemEvent(
            clientId:    $client->id,
            eventType:   $eventType,
            displayText: $text,
            metadata:    $metadata,
        );
    }

    /**
     * Suspender el servicio de un cliente por impago.
     *
     * Intenta bloquear la IP en MikroTik (lista 'morosos') y actualiza el estado
     * en la base de datos. Si MikroTik no está disponible, la suspensión en BD
     * se aplica de todas formas y se registra el fallo para revisión manual.
     *
     * Antes de cualquier acción se consulta la lista blanca: si el cliente
     * tiene una inclusión vigente la suspensión queda bloqueada y se devuelve
     * el resultado con el flag `whitelisted` para trazabilidad.
     *
     * @param  Client      $client     Cliente a suspender
     * @param  string      $reason     Razón descriptiva del corte
     * @param  int|null    $invoiceId  ID de la factura que originó el corte (si aplica)
     * @return array{success: bool, already_suspended?: bool, whitelisted?: bool, mikrotik?: array}
     */
    public function suspendClient(Client $client, string $reason, ?int $invoiceId = null): array
    {
        return $this->withClientLock($client, fn () => $this->applySuspension($client, $reason, $invoiceId));
    }

    private function applySuspension(Client $client, string $reason, ?int $invoiceId = null): array
    {
        if ($this->whitelist->isProtected($client->id)) {
            $entry = $this->whitelist->activeEntryFor($client->id);

            Audit::create([
                'table_name' => 'clients',
                'operation'  => 'SUSPEND_BLOCKED_WHITELIST',
                'record_id'  => (string) $client->id,
                'old_values' => ['service_status' => $client->service_status],
                'new_values' => [
                    'service_status' => $client->service_status,
                    'reason'         => $reason,
                    'invoice_id'     => $invoiceId,
                    'whitelist_id'   => $entry?->id,
                    'whitelist_reason' => $entry?->reason,
                    'whitelist_expires_at' => optional($entry?->expires_at)->toIso8601String(),
                    'executor'       => 'system_auto',
                    'timestamp'      => now()->toIso8601String(),
                ],
                'user_id'    => null,
                'ip_address' => '127.0.0.1',
            ]);

            Log::info("ClientSuspensionService: Suspensión bloqueada por lista blanca para cliente {$client->id}.", [
                'whitelist_id' => $entry?->id,
                'reason'       => $reason,
            ]);

            return [
                'success'     => true,
                'whitelisted' => true,
            ];
        }

        if (in_array(strtoupper($client->service_status), ['SUSPENDED', 'SUSPENDIDO'])) {
            return ['success' => true, 'already_suspended' => true];
        }

        $mkResult         = ['skipped' => 'no_ip'];
        $enforcementState = ClientServiceInterruption::ENFORCEMENT_NO_IP;

        if (!$client->ip) {
            Log::warning("ClientSuspensionService: Cliente {$client->id} sin IP. Suspendido solo en BD.");
        } elseif (($conflictingIp = $this->conflictingQueueIp($client)) !== null) {
            // La IP registrada ya no es la del cliente: bloquearla cortaría a
            // otro abonado. Se suspende en BD y se deja el conflicto marcado
            // para que la conciliación y el operador lo vean.
            $mkResult = ['skipped' => 'ip_mismatch', 'queue_target_ip' => $conflictingIp, 'client_ip' => $client->ip];
            $enforcementState = ClientServiceInterruption::ENFORCEMENT_IP_MISMATCH;

            Log::error("ClientSuspensionService: IP del cliente {$client->id} no coincide con su cola en el router. No se bloquea para no cortar a otro abonado.", [
                'client_ip'       => $client->ip,
                'queue_target_ip' => $conflictingIp,
            ]);
        } else {
            try {
                $mkResult = $this->mikrotik->addIpToAddressList(
                    $client->ip,
                    $this->morososList(),
                    "Suspensión automática - {$reason} - " . now()->format('Y-m-d H:i')
                );

                if (!$mkResult['success'] && !($mkResult['already_exists'] ?? false)) {
                    Log::error("ClientSuspensionService: MikroTik falló para cliente {$client->id}.", [
                        'mikrotik_response' => $mkResult,
                        'reason'            => $reason,
                    ]);
                    $enforcementState = ClientServiceInterruption::ENFORCEMENT_ENTRY_MISSING;
                } else {
                    // La escritura en la lista no basta: sin una regla de firewall
                    // que la use, el abonado sigue navegando. Aquí se comprueba.
                    $enforcementState = $this->verifyEnforcement($client);

                    if ($enforcementState !== ClientServiceInterruption::ENFORCEMENT_ENFORCED) {
                        Log::error("ClientSuspensionService: el corte del cliente {$client->id} NO se pudo confirmar en la red ({$enforcementState}).", [
                            'ip'    => $client->ip,
                            'lista' => $this->morososList(),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::error("ClientSuspensionService: Excepción MikroTik para cliente {$client->id}: " . $e->getMessage());
                $mkResult = ['success' => false, 'error' => $e->getMessage()];
                $enforcementState = ClientServiceInterruption::ENFORCEMENT_UNVERIFIABLE;
            }
        }

        // Datos de la factura que disparó el corte (si aplica) para trazabilidad.
        $invoiceTrace = null;
        if ($invoiceId) {
            $invoice = Invoice::find($invoiceId);
            if ($invoice) {
                $issue = $invoice->issue_date instanceof \DateTimeInterface
                    ? \Carbon\Carbon::instance($invoice->issue_date)
                    : \Carbon\Carbon::parse($invoice->issue_date);
                $due = $invoice->due_date instanceof \DateTimeInterface
                    ? \Carbon\Carbon::instance($invoice->due_date)
                    : \Carbon\Carbon::parse($invoice->due_date);

                $invoiceTrace = [
                    'invoice_id'        => $invoice->id,
                    'invoice_number'    => $invoice->invoice_number,
                    'issue_date'        => $issue->toDateString(),
                    'due_date'          => $due->toDateString(),
                    'configured_due_days' => $issue->diffInDays($due),
                ];
            }
        }

        // La suspensión en BD se aplica siempre, aunque MikroTik haya fallado
        DB::transaction(function () use ($client, $reason, $invoiceId, $mkResult, $invoiceTrace, $enforcementState) {
            $oldStatus = $client->service_status;

            // El observer abre la ventana de corte con este contexto (fecha de
            // suspensión que la facturación usará como límite de emisión).
            $client->serviceStatusChangeContext = [
                'reason'            => $reason,
                'executor'          => 'system_auto',
                'invoice_id'        => $invoiceId,
                'source'            => 'auto',
                'enforcement_state' => $enforcementState,
            ];
            $client->service_status = 'suspended';
            $client->save();

            // Los mass updates no disparan eventos de Eloquent (el trait
            // Auditable no los ve), así que capturamos los planes afectados
            // para dejarlos trazados en el registro de auditoría.
            $affectedPlanIds = ClientPlan::where('client_id', $client->id)
                ->where('status', 'active')
                ->pluck('id')
                ->all();

            ClientPlan::whereIn('id', $affectedPlanIds)
                ->update(['status' => 'suspended']);

            Audit::create([
                'table_name' => 'clients',
                'operation'  => 'SUSPEND_AUTO_OP',
                'record_id'  => (string) $client->id,
                'old_values' => ['service_status' => $oldStatus],
                'new_values' => [
                    'service_status'   => 'suspended',
                    'ip'               => $client->ip,
                    'reason'           => $reason,
                    'invoice_id'       => $invoiceId,
                    'invoice_trace'    => $invoiceTrace,
                    'suspension_activated_at' => now()->toIso8601String(),
                    'mikrotik_list'    => $this->morososList(),
                    'mikrotik_result'  => $mkResult,
                    'enforcement_state' => $enforcementState,
                    'plans_affected'   => $affectedPlanIds,
                    'executor'         => 'system_auto',
                    'timestamp'        => now()->toIso8601String(),
                ],
                'user_id'    => null,
                'ip_address' => '127.0.0.1',
            ]);
        });

        Log::info("ClientSuspensionService: Cliente {$client->id} suspendido. Razón: {$reason}");

        $this->notifyClient(
            $client,
            'service_suspended',
            'Servicio suspendido por facturas pendientes. Recarga tu billetera para restablecerlo automáticamente.',
            [
                'reason'     => $reason,
                'invoice_id' => $invoiceId,
                'actor_type' => 'system',
                'occurred_at' => now()->toIso8601String(),
            ],
        );

        return [
            'success'           => true,
            'mikrotik'          => $mkResult,
            'enforcement_state' => $enforcementState,
            'enforced'          => $enforcementState === ClientServiceInterruption::ENFORCEMENT_ENFORCED,
        ];
    }

    /**
     * Dar de baja a un cliente (baja lógica / cancelación definitiva).
     *
     * Regla de negocio: solo se permite dar de baja a clientes SUSPENDIDOS, es
     * decir, con el servicio ya cortado. La baja:
     *   - marca al cliente como 'cancelled' y todos sus planes vigentes como
     *     'cancelled' (con end_date = hoy), conservando todo el historial;
     *   - libera los recursos del cliente en MikroTik (elimina sus colas) para
     *     recuperar capacidad del ISP — best-effort: si MikroTik falla, la baja
     *     en BD se aplica igualmente y el fallo queda registrado;
     *   - deja al cliente fuera de TODO proceso automatizado (facturación,
     *     suspensión y reactivación ya filtran por estado de servicio/plan).
     *
     * @return array{success: bool, code?: string, message?: string, already_cancelled?: bool, mikrotik?: array}
     */
    public function cancelClient(Client $client, string $reason, ?int $employeeId = null, ?string $ipAddress = null): array
    {
        return $this->withClientLock($client, fn () => $this->applyCancellation($client, $reason, $employeeId, $ipAddress));
    }

    private function applyCancellation(Client $client, string $reason, ?int $employeeId = null, ?string $ipAddress = null): array
    {
        $status = strtoupper((string) $client->service_status);

        if (in_array($status, ['CANCELLED', 'CANCELADO'], true)) {
            return ['success' => true, 'already_cancelled' => true];
        }

        if (!in_array($status, ['SUSPENDED', 'SUSPENDIDO'], true)) {
            return [
                'success' => false,
                'code'    => 'NOT_SUSPENDED',
                'message' => 'Solo se puede dar de baja a clientes que estén suspendidos.',
            ];
        }

        // 1) Liberar recursos en MikroTik (eliminar colas) para recuperar capacidad.
        $client->loadMissing(['clientPlans.plan']);
        $mkResults = $this->releaseClientNetworkResources($client);

        // 2) BD: marcar cliente y planes como cancelados (se conserva el historial).
        DB::transaction(function () use ($client, $reason, $employeeId, $ipAddress, $mkResults) {
            $oldStatus = $client->service_status;

            // El observer mantiene/abre la ventana de corte como 'cancellation'.
            $client->serviceStatusChangeContext = [
                'reason'   => $reason,
                'executor' => $employeeId ? "employee:{$employeeId}" : 'system',
                'source'   => $employeeId ? 'manual' : 'auto',
            ];
            $client->service_status = 'cancelled';
            $client->save();

            $affectedPlanIds = ClientPlan::where('client_id', $client->id)
                ->where('status', '!=', 'cancelled')
                ->pluck('id')
                ->all();

            ClientPlan::whereIn('id', $affectedPlanIds)
                ->update(['status' => 'cancelled', 'end_date' => now()]);

            Audit::create([
                'table_name' => 'clients',
                'operation'  => 'CANCEL_OP',
                'record_id'  => (string) $client->id,
                'old_values' => ['service_status' => $oldStatus],
                'new_values' => [
                    'service_status'  => 'cancelled',
                    'reason'          => $reason,
                    'plans_cancelled' => true,
                    'plans_affected'  => $affectedPlanIds,
                    'mikrotik_result' => $mkResults,
                    'executor'        => $employeeId ? "employee:{$employeeId}" : 'system',
                    'timestamp'       => now()->toIso8601String(),
                ],
                'user_id'    => $employeeId,
                'user_type'  => $employeeId ? \App\Models\Employee::class : null,
                'ip_address' => $ipAddress ?? '127.0.0.1',
            ]);
        });

        Log::info("ClientSuspensionService: Cliente {$client->id} dado de baja. Razón: {$reason}");

        $this->notifyClient(
            $client,
            'service_cancelled',
            'Tu servicio ha sido dado de baja. Si crees que es un error, contacta con soporte.',
            [
                'reason'      => $reason,
                'actor_type'  => $employeeId ? 'employee' : 'system',
                'occurred_at' => now()->toIso8601String(),
            ],
        );

        return ['success' => true, 'mikrotik' => $mkResults];
    }

    /**
     * Elimina las colas del cliente en MikroTik para liberar capacidad del ISP.
     * Cada plan se procesa de forma independiente y tolerante a fallos: un error
     * en MikroTik (p. ej. router caído) no impide completar la baja en la BD.
     *
     * @return array<int, array{client_plan_id?: int, success: bool, error?: string}>
     */
    private function releaseClientNetworkResources(Client $client): array
    {
        try {
            /** @var \App\Services\MikroTikQueueSyncService $queueSync */
            $queueSync = app(MikroTikQueueSyncService::class);
        } catch (\Throwable $e) {
            Log::warning("ClientSuspensionService: no se pudo resolver el sincronizador de colas para la baja del cliente {$client->id}: " . $e->getMessage());
            return [['success' => false, 'error' => $e->getMessage()]];
        }

        $results = [];

        foreach ($client->clientPlans as $clientPlan) {
            try {
                $queueSync->removeClientQueueAndRecalculate($client, $clientPlan, $clientPlan->plan);
                $results[] = ['client_plan_id' => $clientPlan->id, 'success' => true];
            } catch (\Throwable $e) {
                Log::warning("ClientSuspensionService: fallo al liberar la cola del cliente {$client->id} (plan {$clientPlan->id}): " . $e->getMessage());
                $results[] = ['client_plan_id' => $clientPlan->id, 'success' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Reactivar el servicio de un cliente.
     *
     * Remueve la IP de la lista 'morosos' en MikroTik y restaura el estado en BD.
     *
     * @param  Client  $client  Cliente a reactivar
     * @param  string  $reason  Razón de la reactivación
     * @return array{success: bool, already_active?: bool, mikrotik?: array}
     */
    public function reactivateClient(Client $client, string $reason): array
    {
        return $this->withClientLock($client, fn () => $this->applyReactivation($client, $reason));
    }

    private function applyReactivation(Client $client, string $reason): array
    {
        if (in_array(strtoupper($client->service_status), ['ACTIVE', 'ACTIVO'])) {
            return ['success' => true, 'already_active' => true];
        }

        // Una baja es definitiva: reactivarla dejaba al cliente 'active' con
        // todos sus planes en 'cancelled' — activo, sin servicio contratado y
        // fuera de la facturación. El alta de un cliente dado de baja pasa por
        // contratar un plan nuevo, no por esta vía.
        if (in_array(strtoupper((string) $client->service_status), ['CANCELLED', 'CANCELADO'], true)) {
            Log::warning("ClientSuspensionService: intento de reactivar al cliente {$client->id}, que está dado de baja.");

            return [
                'success' => false,
                'code'    => 'CANCELLED',
                'message' => 'No se puede reactivar a un cliente dado de baja: debe contratarse un plan nuevo.',
            ];
        }

        $mkResult = ['skipped' => 'no_ip'];

        if ($client->ip) {
            try {
                $mkResult = $this->mikrotik->removeIpFromAddressList($client->ip, $this->morososList());

                if (!$mkResult['success'] && !($mkResult['not_found'] ?? false)) {
                    Log::error("ClientSuspensionService: MikroTik falló al reactivar cliente {$client->id}.", [
                        'mikrotik_response' => $mkResult,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error("ClientSuspensionService: Excepción MikroTik al reactivar cliente {$client->id}: " . $e->getMessage());
                $mkResult = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        DB::transaction(function () use ($client, $reason, $mkResult) {
            $oldStatus = $client->service_status;

            // El observer cierra la ventana de corte con la fecha de
            // reactivación: desde aquí la facturación se reanuda.
            $client->serviceStatusChangeContext = [
                'reason'   => $reason,
                'executor' => 'system_auto',
                'source'   => 'auto',
            ];
            $client->service_status = 'active';
            $client->save();

            $affectedPlanIds = ClientPlan::where('client_id', $client->id)
                ->where('status', 'suspended')
                ->pluck('id')
                ->all();

            ClientPlan::whereIn('id', $affectedPlanIds)
                ->update(['status' => 'active']);

            Audit::create([
                'table_name' => 'clients',
                'operation'  => 'REACTIVATE_AUTO_OP',
                'record_id'  => (string) $client->id,
                'old_values' => ['service_status' => $oldStatus],
                'new_values' => [
                    'service_status'  => 'active',
                    'ip'              => $client->ip,
                    'reason'          => $reason,
                    'mikrotik_list'   => $this->morososList(),
                    'mikrotik_result' => $mkResult,
                    'plans_affected'  => $affectedPlanIds,
                    'executor'        => 'system_auto',
                    'timestamp'       => now()->toIso8601String(),
                ],
                'user_id'    => null,
                'ip_address' => '127.0.0.1',
            ]);
        });

        Log::info("ClientSuspensionService: Cliente {$client->id} reactivado. Razón: {$reason}");

        $this->notifyClient(
            $client,
            'service_restored',
            'Tu servicio ha sido restablecido. Ya puedes navegar con normalidad.',
            [
                'reason'      => $reason,
                'actor_type'  => 'system',
                'occurred_at' => now()->toIso8601String(),
            ],
        );

        return ['success' => true, 'mikrotik' => $mkResult];
    }
}
