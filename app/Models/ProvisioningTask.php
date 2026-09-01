<?php

namespace App\Models;

use App\Enums\ProvisioningTaskStatus;
use App\Enums\ProvisioningTaskType;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Unidad de trabajo que un agente reclama por polling.
 *
 * El `payload` va cifrado en reposo porque puede transportar las credenciales
 * con las que el agente entra al router. Y es siempre una instrucción
 * estructurada —nunca un comando crudo—: el agente valida cada campo contra su
 * propia lista blanca antes de ejecutarlo, de modo que ni siquiera un servidor
 * comprometido puede hacerle ejecutar algo arbitrario en la red.
 */
class ProvisioningTask extends Model
{
    use Auditable;

    /**
     * El payload cambia en cada reintento y no aporta nada al historial; el
     * resultado sí queda registrado por el auditor de la saga.
     */
    protected static function auditIgnoredFields(): array
    {
        return ['payload'];
    }

    protected $fillable = [
        'session_id',
        'agent_id',
        'type',
        'payload',
        'status',
        'attempts',
        'claimed_at',
        'finished_at',
        'expires_at',
        'result',
        'error_code',
        'error_message',
    ];

    protected $hidden = ['payload'];

    protected $casts = [
        'type'        => ProvisioningTaskType::class,
        'status'      => ProvisioningTaskStatus::class,
        'payload'     => 'encrypted:array',
        'result'      => 'array',
        'attempts'    => 'integer',
        'claimed_at'  => 'datetime',
        'finished_at' => 'datetime',
        'expires_at'  => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(DeviceProvisioningSession::class, 'session_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(ProvisioningAgent::class, 'agent_id');
    }

    // ── Transiciones ─────────────────────────────────────────────────────────

    public function markClaimed(): void
    {
        $this->forceFill([
            'status'     => ProvisioningTaskStatus::CLAIMED,
            'claimed_at' => now(),
            'attempts'   => $this->attempts + 1,
            'expires_at' => now()->addSeconds($this->type->defaultTimeoutSeconds()),
        ])->save();
    }

    public function markSucceeded(array $result): void
    {
        $this->forceFill([
            'status'      => ProvisioningTaskStatus::SUCCEEDED,
            'result'      => $result,
            'finished_at' => now(),
        ])->save();
    }

    public function markFailed(?string $code, ?string $message, array $result = []): void
    {
        $this->forceFill([
            'status'        => ProvisioningTaskStatus::FAILED,
            'result'        => $result,
            'error_code'    => $code,
            'error_message' => $message,
            'finished_at'   => now(),
        ])->save();
    }

    public function markExpired(): void
    {
        $this->forceFill([
            'status'        => ProvisioningTaskStatus::EXPIRED,
            'error_code'    => 'TASK_TIMEOUT',
            'error_message' => "El agente no reportó dentro de {$this->type->defaultTimeoutSeconds()}s.",
            'finished_at'   => now(),
        ])->save();
    }

    /**
     * Líneas de log que el agente adjuntó al reportar. Alimentan el canal
     * `provisioning` para que la traza del proceso viva también del lado del
     * servidor y no solo en el journald de la máquina remota.
     *
     * @return list<string>
     */
    public function agentLogs(): array
    {
        $logs = data_get($this->result, 'logs', []);

        return is_array($logs) ? array_values(array_filter($logs, 'is_string')) : [];
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeClaimable(Builder $query): Builder
    {
        return $query->where('status', ProvisioningTaskStatus::PENDING->value);
    }

    /**
     * Tareas vencidas. Cubre los dos casos, y ambos importan: una `claimed` que
     * venció es un agente que murió a mitad de aplicar; una `pending` que venció
     * es un agente que nunca llegó a recogerla (caído o revocado). Las dos
     * dejan la sesión colgada si nadie las vence.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereIn('status', [
                ProvisioningTaskStatus::PENDING->value,
                ProvisioningTaskStatus::CLAIMED->value,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());
    }

    /**
     * Representación que se entrega al agente al reclamar. Incluye el payload
     * descifrado — es el único punto del sistema donde eso ocurre.
     */
    public function toAgentArray(): array
    {
        return [
            'id'         => $this->id,
            'session_id' => $this->session_id,
            'type'       => $this->type->value,
            'payload'    => $this->payload ?? [],
            'expires_at' => $this->expires_at?->toIso8601String(),
            'attempt'    => $this->attempts,
        ];
    }

    public function toApiArray(): array
    {
        return [
            'id'            => $this->id,
            'type'          => $this->type->value,
            'type_label'    => $this->type->label(),
            'status'        => $this->status->value,
            'attempts'      => $this->attempts,
            'claimed_at'    => $this->claimed_at?->toIso8601String(),
            'finished_at'   => $this->finished_at?->toIso8601String(),
            'error_code'    => $this->error_code,
            'error_message' => $this->error_message,
            'logs'          => $this->agentLogs(),
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
