<?php

namespace App\Models;

use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningTaskType;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Un intento de dar de alta un dispositivo, de punta a punta.
 *
 * Es el agregado que conduce la saga: guarda qué se detectó, qué se aplicó en
 * cada extremo y —sobre todo— la pila de compensaciones. Cada paso que modifica
 * algo apila su reversión; si un paso posterior falla, se desapilan en orden
 * inverso. Sin esa pila un fallo a mitad de camino dejaría una interfaz
 * WireGuard huérfana en el router o un peer fantasma en el hosting.
 *
 * La fila de `network_devices` se crea al final, no al principio: hacerlo antes
 * significaría que ante un fallo la regla "el primer router es primary"
 * (MikrotikRouter::booted) ya habría dejado al sistema devolviendo 423 en todas
 * las rutas con middleware `primary_router`.
 */
class DeviceProvisioningSession extends Model
{
    use Auditable;

    /**
     * `compensations` y `context` cambian en cada paso y su contenido ya queda
     * registrado con detalle por ProvisioningAuditor; incluirlos aquí solo
     * duplicaría ruido en el historial.
     */
    protected static function auditIgnoredFields(): array
    {
        return ['compensations', 'context'];
    }

    protected $fillable = [
        'agent_id',
        'router_id',
        'status',
        'detection_method',
        'mac_address',
        'identity',
        'board_name',
        'routeros_version',
        'serial_number',
        'link_interface',
        'lan_ip',
        'vpn_interface',
        'vpn_assigned_ip',
        'vpn_router_public_key',
        'vpn_endpoint',
        'compensations',
        'context',
        'error_code',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $hidden = ['context'];

    protected $casts = [
        'status'        => ProvisioningStatus::class,
        'compensations' => 'array',
        // Cifrado: durante el alta transporta la contraseña generada para el
        // usuario de gestión del router. `$hidden` además lo mantiene fuera de
        // las auditorías, porque el trait descarta los campos ocultos.
        'context'       => 'encrypted:array',
        'started_at'    => 'datetime',
        'completed_at'  => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(ProvisioningAgent::class, 'agent_id');
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(MikrotikRouter::class, 'router_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProvisioningTask::class, 'session_id');
    }

    public function vpnProfile(): HasOne
    {
        return $this->hasOne(RouterVpnProfile::class, 'session_id');
    }

    // ── Pila de compensaciones ───────────────────────────────────────────────

    /**
     * Apila la reversión de un paso que acaba de aplicarse. El payload lleva lo
     * imprescindible para deshacerlo sin depender del estado actual de la fila
     * (que puede haber cambiado cuando toque revertir).
     */
    public function pushCompensation(ProvisioningTaskType $type, array $payload): void
    {
        $stack   = $this->compensations ?? [];
        $stack[] = ['type' => $type->value, 'payload' => $payload];

        $this->forceFill(['compensations' => $stack])->save();
    }

    /**
     * Desapila la siguiente compensación pendiente (LIFO).
     *
     * @return array{type: ProvisioningTaskType, payload: array}|null
     */
    public function popCompensation(): ?array
    {
        $stack = $this->compensations ?? [];
        if ($stack === []) {
            return null;
        }

        $entry = array_pop($stack);
        $this->forceFill(['compensations' => $stack])->save();

        $type = ProvisioningTaskType::tryFrom($entry['type'] ?? '');
        if ($type === null) {
            // Entrada corrupta: se descarta y se sigue desapilando para no
            // bloquear el rollback del resto de pasos.
            return $this->popCompensation();
        }

        return ['type' => $type, 'payload' => $entry['payload'] ?? []];
    }

    public function hasPendingCompensations(): bool
    {
        return ($this->compensations ?? []) !== [];
    }

    // ── Contexto volátil ─────────────────────────────────────────────────────

    public function contextValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->context, $key, $default);
    }

    public function mergeContext(array $values): void
    {
        $this->forceFill(['context' => array_merge($this->context ?? [], $values)])->save();
    }

    // ── Transiciones ─────────────────────────────────────────────────────────

    public function transitionTo(ProvisioningStatus $status): void
    {
        $this->forceFill([
            'status'     => $status,
            'started_at' => $this->started_at ?? now(),
        ])->save();
    }

    public function markFailed(string $code, string $message): void
    {
        $this->forceFill([
            'status'        => ProvisioningStatus::FAILED,
            'error_code'    => $code,
            'error_message' => $message,
            'completed_at'  => now(),
        ])->save();
    }

    public function markRolledBack(): void
    {
        $this->forceFill([
            'status'       => ProvisioningStatus::ROLLED_BACK,
            'completed_at' => now(),
        ])->save();
    }

    public function markCompleted(MikrotikRouter $router): void
    {
        $this->forceFill([
            'status'       => ProvisioningStatus::COMPLETED,
            'router_id'    => $router->id,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Tarea viva (pendiente o reclamada) de esta sesión, si la hay. La saga
     * avanza de una en una: mientras exista, no se encola nada nuevo.
     */
    public function currentTask(): ?ProvisioningTask
    {
        return $this->tasks()
            ->whereIn('status', ['pending', 'claimed'])
            ->orderByDesc('id')
            ->first();
    }

    public function lastTaskOfType(ProvisioningTaskType $type): ?ProvisioningTask
    {
        return $this->tasks()
            ->where('type', $type->value)
            ->orderByDesc('id')
            ->first();
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            ProvisioningStatus::COMPLETED->value,
            ProvisioningStatus::FAILED->value,
            ProvisioningStatus::ROLLED_BACK->value,
            ProvisioningStatus::CANCELLED->value,
        ]);
    }

    /**
     * Sesión viva del mismo equipo. Evita abrir una segunda cuando el agente
     * vuelve a ver el mismo router (MNDP repite cada 60 s por diseño).
     */
    public static function activeForDevice(?string $macAddress, ?string $serialNumber): ?self
    {
        if ($macAddress === null && $serialNumber === null) {
            return null;
        }

        return static::query()
            ->active()
            ->where(function (Builder $q) use ($macAddress, $serialNumber) {
                if ($macAddress !== null) {
                    $q->orWhere('mac_address', $macAddress);
                }
                if ($serialNumber !== null) {
                    $q->orWhere('serial_number', $serialNumber);
                }
            })
            ->orderByDesc('id')
            ->first();
    }

    public function toApiArray(bool $withTasks = false): array
    {
        $data = [
            'id'               => $this->id,
            'status'           => $this->status->value,
            'status_label'     => $this->status->label(),
            'step_index'       => $this->status->stepIndex(),
            'is_terminal'      => $this->status->isTerminal(),
            'detection_method' => $this->detection_method,
            'agent'            => [
                'id'   => $this->agent_id,
                'name' => $this->relationLoaded('agent') ? $this->agent?->name : null,
            ],
            'device' => [
                'mac_address'      => $this->mac_address,
                'identity'         => $this->identity,
                'board_name'       => $this->board_name,
                'routeros_version' => $this->routeros_version,
                'serial_number'    => $this->serial_number,
                'link_interface'   => $this->link_interface,
                'lan_ip'           => $this->lan_ip,
            ],
            'vpn' => [
                'interface'   => $this->vpn_interface,
                'assigned_ip' => $this->vpn_assigned_ip,
                'endpoint'    => $this->vpn_endpoint,
                'public_key'  => $this->vpn_router_public_key,
            ],
            'router_id'     => $this->router_id,
            'error_code'    => $this->error_code,
            'error_message' => $this->error_message,
            'started_at'    => $this->started_at?->toIso8601String(),
            'completed_at'  => $this->completed_at?->toIso8601String(),
            'created_at'    => $this->created_at?->toIso8601String(),
        ];

        if ($withTasks) {
            $data['tasks'] = $this->tasks()
                ->orderBy('id')
                ->get()
                ->map(fn (ProvisioningTask $t) => $t->toApiArray())
                ->all();
        }

        return $data;
    }
}
