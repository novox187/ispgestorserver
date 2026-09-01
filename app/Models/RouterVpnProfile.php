<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perfil del túnel WireGuard vigente de un router.
 *
 * Aquí no hay ninguna clave privada y no es un descuido: la del router la
 * genera el propio RouterOS al crear la interfaz y solo se lee su pública; la
 * del servidor vive en el sistema operativo del hosting y el agente `vpn_host`
 * únicamente publica su contraparte pública. Ninguna clave privada viaja por la
 * API ni se persiste en esta base de datos.
 *
 * La unicidad de `assigned_ip` es la última red de seguridad del asignador de
 * direcciones: aunque dos sesiones concurrentes burlasen el `lockForUpdate`, la
 * base de datos rechazaría la segunda.
 */
class RouterVpnProfile extends Model
{
    use Auditable;

    /** El handshake se actualiza en cada verificación; auditarlo sería ruido. */
    protected static function auditIgnoredFields(): array
    {
        return ['last_handshake_at'];
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'router_id',
        'session_id',
        'driver',
        'interface_name',
        'assigned_ip',
        'released_ip',
        'router_public_key',
        'server_public_key',
        'endpoint_host',
        'endpoint_port',
        'allowed_ips',
        'keepalive',
        'status',
        'last_handshake_at',
    ];

    protected $casts = [
        'endpoint_port'     => 'integer',
        'keepalive'         => 'integer',
        'last_handshake_at' => 'datetime',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(MikrotikRouter::class, 'router_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(DeviceProvisioningSession::class, 'session_id');
    }

    public function markActive(?\DateTimeInterface $handshakeAt = null): void
    {
        $this->forceFill([
            'status'            => self::STATUS_ACTIVE,
            'last_handshake_at' => $handshakeAt ?? now(),
        ])->save();
    }

    /**
     * Revoca el perfil sin borrarlo. La dirección pasa a `released_ip` y
     * `assigned_ip` queda a null, con lo que el índice único deja de retenerla
     * y vuelve al pool — pero sigue constando quién la ocupó, que es lo que
     * hace auditable el reciclaje de direcciones.
     */
    public function revoke(): void
    {
        $this->forceFill([
            'status'      => self::STATUS_REVOKED,
            'released_ip' => $this->assigned_ip ?? $this->released_ip,
            'assigned_ip' => null,
        ])->save();
    }

    public function endpoint(): string
    {
        return "{$this->endpoint_host}:{$this->endpoint_port}";
    }

    public function scopeHolding(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_FAILED]);
    }

    public function toApiArray(): array
    {
        return [
            'id'                => $this->id,
            'router_id'         => $this->router_id,
            'session_id'        => $this->session_id,
            'driver'            => $this->driver,
            'interface_name'    => $this->interface_name,
            'assigned_ip'       => $this->assigned_ip ?? $this->released_ip,
            'router_public_key' => $this->router_public_key,
            'endpoint'          => $this->endpoint(),
            'allowed_ips'       => $this->allowed_ips,
            'keepalive'         => $this->keepalive,
            'status'            => $this->status,
            'last_handshake_at' => $this->last_handshake_at?->toIso8601String(),
        ];
    }
}
