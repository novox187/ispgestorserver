<?php

namespace App\Services;

use App\Models\Audit;
use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientSuspensionService
{
    public function __construct(
        private readonly MikroTikService $mikrotik,
        private readonly ClientWhitelistService $whitelist,
    ) {}

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

        $mkResult = ['skipped' => 'no_ip'];

        // Intentar bloquear en MikroTik si el cliente tiene IP asignada
        if ($client->ip) {
            try {
                $mkResult = $this->mikrotik->addIpToAddressList(
                    $client->ip,
                    'morosos',
                    "Suspensión automática - {$reason} - " . now()->format('Y-m-d H:i')
                );

                if (!$mkResult['success'] && !($mkResult['already_exists'] ?? false)) {
                    Log::error("ClientSuspensionService: MikroTik falló para cliente {$client->id}.", [
                        'mikrotik_response' => $mkResult,
                        'reason'            => $reason,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error("ClientSuspensionService: Excepción MikroTik para cliente {$client->id}: " . $e->getMessage());
                $mkResult = ['success' => false, 'error' => $e->getMessage()];
            }
        } else {
            Log::warning("ClientSuspensionService: Cliente {$client->id} sin IP. Suspendido solo en BD.");
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
        DB::transaction(function () use ($client, $reason, $invoiceId, $mkResult, $invoiceTrace) {
            $oldStatus = $client->service_status;

            // El observer abre la ventana de corte con este contexto (fecha de
            // suspensión que la facturación usará como límite de emisión).
            $client->serviceStatusChangeContext = [
                'reason'     => $reason,
                'executor'   => 'system_auto',
                'invoice_id' => $invoiceId,
                'source'     => 'auto',
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
                    'mikrotik_list'    => 'morosos',
                    'mikrotik_result'  => $mkResult,
                    'plans_affected'   => $affectedPlanIds,
                    'executor'         => 'system_auto',
                    'timestamp'        => now()->toIso8601String(),
                ],
                'user_id'    => null,
                'ip_address' => '127.0.0.1',
            ]);
        });

        Log::info("ClientSuspensionService: Cliente {$client->id} suspendido. Razón: {$reason}");

        return ['success' => true, 'mikrotik' => $mkResult];
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
        if (in_array(strtoupper($client->service_status), ['ACTIVE', 'ACTIVO'])) {
            return ['success' => true, 'already_active' => true];
        }

        $mkResult = ['skipped' => 'no_ip'];

        if ($client->ip) {
            try {
                $mkResult = $this->mikrotik->removeIpFromAddressList($client->ip, 'morosos');

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
                    'mikrotik_list'   => 'morosos',
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

        return ['success' => true, 'mikrotik' => $mkResult];
    }
}
