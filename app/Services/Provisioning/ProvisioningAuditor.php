<?php

namespace App\Services\Provisioning;

use App\Models\Audit;
use App\Models\DeviceProvisioningSession;
use App\Models\ProvisioningAgent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * Punto único de escritura en `audits` para el aprovisionamiento de dispositivos.
 *
 * Sigue la convención ya establecida en el proyecto para eventos que no nacen de
 * un modelo Eloquent (ver `mikrotik_queue_sync` en MikroTikController y
 * `auditMikroTik()` en MikroTikQueueSyncService): `table_name` es una
 * pseudo-tabla y `operation` un verbo del dominio.
 *
 * Como `record_id` es el id de la sesión, `Audit::forRecord('device_provisioning', $id)`
 * devuelve la traza completa de un alta, y el visor de auditorías existente la
 * muestra sin necesidad de cambios: ya filtra por `table_name` y `record_id`.
 *
 * Dos reglas heredadas del endurecimiento de auditoría previo:
 *  - un fallo al auditar nunca rompe la operación que lo originó;
 *  - las auditorías de fallo se escriben FUERA de la transacción que se va a
 *    revertir, o el rollback las borraría junto con el resto.
 */
class ProvisioningAuditor
{
    public const TABLE_SESSIONS = 'device_provisioning';
    public const TABLE_AGENTS   = 'provisioning_agents';

    /** Canal de log dedicado; ver config/logging.php. */
    public const LOG_CHANNEL = 'provisioning';

    // ── Verbos del ciclo de vida de una sesión ───────────────────────────────
    public const DETECTED              = 'PROVISION_DETECTED';
    public const IDENTIFIED            = 'PROVISION_IDENTIFIED';
    public const REJECTED_INCOMPATIBLE = 'PROVISION_REJECTED_INCOMPATIBLE';
    public const APPROVED              = 'PROVISION_APPROVED';
    public const ROUTER_APPLIED        = 'PROVISION_ROUTER_APPLIED';
    public const HOST_APPLIED          = 'PROVISION_HOST_APPLIED';
    public const VERIFIED              = 'PROVISION_VERIFIED';
    public const COMPLETED             = 'PROVISION_COMPLETED';
    public const STEP_FAILED           = 'PROVISION_STEP_FAILED';
    public const ROLLED_BACK           = 'PROVISION_ROLLED_BACK';
    public const COMPENSATED           = 'PROVISION_COMPENSATED';
    public const CANCELLED             = 'PROVISION_CANCELLED';

    // ── Verbos del canal de agentes ──────────────────────────────────────────
    public const AGENT_ENROLLED    = 'AGENT_ENROLLED';
    public const AGENT_REVOKED     = 'AGENT_REVOKED';
    public const AGENT_AUTH_FAILED = 'AGENT_AUTH_FAILED';

    /**
     * Registra un evento de una sesión de aprovisionamiento.
     *
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>      $after   Se le añaden `executor` y `timestamp`.
     */
    public function session(
        DeviceProvisioningSession $session,
        string $operation,
        array $after = [],
        ?array $before = null,
        ?ProvisioningAgent $agent = null,
    ): void {
        $this->write(
            table:     self::TABLE_SESSIONS,
            operation: $operation,
            recordId:  (string) $session->id,
            before:    $before,
            after:     array_merge($after, [
                'session_status' => $session->status->value,
                'device'         => array_filter([
                    'identity'         => $session->identity,
                    'board_name'       => $session->board_name,
                    'routeros_version' => $session->routeros_version,
                    'serial_number'    => $session->serial_number,
                    'mac_address'      => $session->mac_address,
                ]),
            ]),
            agent: $agent ?? $session->agent,
        );

        $this->log($operation, $session->id, $after);
    }

    /**
     * Registra un evento del ciclo de vida de un agente.
     */
    public function agent(ProvisioningAgent $agent, string $operation, array $after = []): void
    {
        $this->write(
            table:     self::TABLE_AGENTS,
            operation: $operation,
            recordId:  (string) $agent->id,
            before:    null,
            after:     array_merge($after, [
                'agent_name' => $agent->name,
                'agent_role' => $agent->role->value,
            ]),
            agent: null,
        );
    }

    /**
     * Registra un intento de autenticación rechazado.
     *
     * Se audita aunque no haya agente identificable (token desconocido): un
     * barrido de tokens contra el canal M2M es exactamente lo que este registro
     * debe dejar a la vista.
     */
    public function authFailure(string $reason, ?ProvisioningAgent $agent, array $context = []): void
    {
        $this->write(
            table:     self::TABLE_AGENTS,
            operation: self::AGENT_AUTH_FAILED,
            recordId:  (string) ($agent->id ?? 'unknown'),
            before:    null,
            after:     array_merge($context, [
                'reason'     => $reason,
                'agent_name' => $agent?->name,
                'path'       => Request::path(),
                'user_agent' => substr((string) Request::userAgent(), 0, 200),
            ]),
            agent: null,
        );

        Log::channel(self::LOG_CHANNEL)->warning('Autenticación de agente rechazada.', [
            'reason'   => $reason,
            'agent_id' => $agent?->id,
            'ip'       => Request::ip(),
            'path'     => Request::path(),
        ]);
    }

    /**
     * Vuelca al canal `provisioning` las líneas de log que el agente adjuntó a
     * su reporte, para que la traza del proceso viva también del lado del
     * servidor y no solo en el journald de la máquina remota.
     *
     * @param list<string> $lines
     */
    public function agentLogs(DeviceProvisioningSession $session, string $taskType, array $lines): void
    {
        foreach ($lines as $line) {
            Log::channel(self::LOG_CHANNEL)->info("[sesión {$session->id}][{$taskType}] {$line}");
        }
    }

    // ── Interno ──────────────────────────────────────────────────────────────

    private function write(
        string $table,
        string $operation,
        string $recordId,
        ?array $before,
        array $after,
        ?ProvisioningAgent $agent,
    ): void {
        try {
            Audit::create([
                'table_name' => $table,
                'operation'  => $operation,
                'record_id'  => $recordId,
                'old_values' => $before,
                'new_values' => array_merge($after, [
                    'executor'  => $this->executor($agent),
                    'timestamp' => now()->toIso8601String(),
                ]),
                'user_id'    => Auth::id(),
                'user_type'  => Auth::user() ? get_class(Auth::user()) : null,
                'ip_address' => Request::ip() ?? '127.0.0.1',
            ]);
        } catch (Throwable $e) {
            Log::error('ProvisioningAuditor: fallo al registrar auditoría.', [
                'table'     => $table,
                'operation' => $operation,
                'record_id' => $recordId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Quién provocó el evento. Los agentes no son usuarios del sistema, así que
     * `user_id` queda a null y su identidad se refleja aquí — sin esto un paso
     * ejecutado por un agente sería indistinguible de uno del scheduler.
     */
    private function executor(?ProvisioningAgent $agent): string
    {
        if ($agent !== null) {
            return "agent:{$agent->id}:{$agent->role->value}";
        }

        $user = Auth::user();
        if ($user !== null) {
            return class_basename($user) . ':' . $user->id;
        }

        return 'system_auto';
    }

    private function log(string $operation, int $sessionId, array $context): void
    {
        Log::channel(self::LOG_CHANNEL)->info("[sesión {$sessionId}] {$operation}", $context);
    }
}
