<?php

namespace App\Services;

use App\Models\Audit;
use App\Models\Client;
use App\Models\ClientPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientSuspensionService
{
    public function __construct(
        private readonly MikroTikService $mikrotik
    ) {}

    /**
     * Suspender el servicio de un cliente por impago.
     *
     * Intenta bloquear la IP en MikroTik (lista 'morosos') y actualiza el estado
     * en la base de datos. Si MikroTik no está disponible, la suspensión en BD
     * se aplica de todas formas y se registra el fallo para revisión manual.
     *
     * @param  Client      $client     Cliente a suspender
     * @param  string      $reason     Razón descriptiva del corte
     * @param  int|null    $invoiceId  ID de la factura que originó el corte (si aplica)
     * @return array{success: bool, already_suspended?: bool, mikrotik?: array}
     */
    public function suspendClient(Client $client, string $reason, ?int $invoiceId = null): array
    {
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

        // La suspensión en BD se aplica siempre, aunque MikroTik haya fallado
        DB::transaction(function () use ($client, $reason, $invoiceId, $mkResult) {
            $oldStatus = $client->service_status;

            $client->service_status = 'suspended';
            $client->save();

            ClientPlan::where('client_id', $client->id)
                ->where('status', 'active')
                ->update(['status' => 'suspended']);

            Audit::create([
                'table_name' => 'clients',
                'operation'  => 'SUSPEND_AUTO_OP',
                'record_id'  => (string) $client->id,
                'old_values' => ['service_status' => $oldStatus],
                'new_values' => [
                    'service_status'  => 'suspended',
                    'ip'              => $client->ip,
                    'reason'          => $reason,
                    'invoice_id'      => $invoiceId,
                    'mikrotik_list'   => 'morosos',
                    'mikrotik_result' => $mkResult,
                    'executor'        => 'system_auto',
                    'timestamp'       => now()->toIso8601String(),
                ],
                'user_id'    => null,
                'ip_address' => '127.0.0.1',
            ]);
        });

        Log::info("ClientSuspensionService: Cliente {$client->id} suspendido. Razón: {$reason}");

        return ['success' => true, 'mikrotik' => $mkResult];
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

            $client->service_status = 'active';
            $client->save();

            ClientPlan::where('client_id', $client->id)
                ->where('status', 'suspended')
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
