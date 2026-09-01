<?php

namespace App\Models;

use App\Enums\AgentRole;
use App\Enums\ProvisioningTaskType;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Demonio que corre FUERA del contenedor Docker y se conecta hacia la API.
 *
 * La aplicación vive aislada en Coolify y no puede alcanzar ni la NIC donde se
 * enchufa un router ni el WireGuard del sistema operativo del hosting. En vez
 * de intentar salir del contenedor, se invierte la dirección: el agente entra a
 * buscar trabajo por HTTPS. Así no hace falta abrir puertos, ni `host-gateway`,
 * ni tocar la red de Coolify, y de paso el NAT de la oficina deja de importar.
 *
 * Autenticación: cada petición va firmada con HMAC-SHA256 sobre `secret`
 * (ver AuthenticateProvisioningAgent). Ni el token ni el secreto se guardan en
 * claro y ambos están en `$hidden`, así que el trait Auditable los excluye
 * automáticamente de los registros de auditoría.
 */
class ProvisioningAgent extends Model
{
    use Auditable;

    /** Ventana de canje del token de enrolamiento. */
    public const ENROLLMENT_TTL_MINUTES = 30;

    /** Sin heartbeat en este tiempo, el agente se considera caído. */
    public const OFFLINE_AFTER_MINUTES = 5;

    protected $fillable = [
        'name',
        'role',
        'token_hash',
        'secret',
        'enrollment_token_hash',
        'enrollment_expires_at',
        'enrolled_at',
        'is_active',
        'last_seen_at',
        'last_ip',
        'agent_version',
        'capabilities',
    ];

    protected $hidden = [
        'secret',
        'token_hash',
        'enrollment_token_hash',
    ];

    protected $casts = [
        'role'                  => AgentRole::class,
        'secret'                => 'encrypted',
        'is_active'             => 'boolean',
        'capabilities'          => 'array',
        'enrollment_expires_at' => 'datetime',
        'enrolled_at'           => 'datetime',
        'last_seen_at'          => 'datetime',
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(ProvisioningTask::class, 'agent_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(DeviceProvisioningSession::class, 'agent_id');
    }

    // ── Enrolamiento ─────────────────────────────────────────────────────────

    /**
     * Genera el token de un solo uso que el administrador entrega al agente.
     * Solo se devuelve aquí en claro: en la fila queda únicamente su hash.
     */
    public function issueEnrollmentToken(): string
    {
        $token = Str::random(64);

        $this->forceFill([
            'enrollment_token_hash' => static::hashToken($token),
            'enrollment_expires_at' => now()->addMinutes(self::ENROLLMENT_TTL_MINUTES),
            'token_hash'            => null,
            'secret'                => null,
            'enrolled_at'           => null,
        ])->save();

        return $token;
    }

    /**
     * Canjea el token de enrolamiento por las credenciales permanentes.
     *
     * @return array{token: string, secret: string} Valores en claro; el llamador
     *         los devuelve al agente una única vez y no vuelven a existir.
     */
    public function completeEnrollment(): array
    {
        $token  = Str::random(64);
        $secret = Str::random(64);

        $this->forceFill([
            'token_hash'            => static::hashToken($token),
            'secret'                => $secret,
            'enrollment_token_hash' => null,
            'enrollment_expires_at' => null,
            'enrolled_at'           => now(),
            'is_active'             => true,
        ])->save();

        return ['token' => $token, 'secret' => $secret];
    }

    public function hasPendingEnrollment(): bool
    {
        return $this->enrollment_token_hash !== null
            && $this->enrollment_expires_at !== null
            && $this->enrollment_expires_at->isFuture();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Busca por el token en claro sin exponerlo: se compara el hash, que está
     * indexado y es único.
     */
    public static function findByToken(string $token): ?self
    {
        return static::query()->where('token_hash', static::hashToken($token))->first();
    }

    public static function findByEnrollmentToken(string $token): ?self
    {
        return static::query()
            ->where('enrollment_token_hash', static::hashToken($token))
            ->first();
    }

    // ── Autorización ─────────────────────────────────────────────────────────

    public function canExecute(ProvisioningTaskType $type): bool
    {
        return $this->is_active && $this->role->allows($type);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subMinutes(self::OFFLINE_AFTER_MINUTES));
    }

    /**
     * Dato publicado por el agente en su enrolamiento/heartbeat. Para el rol
     * `vpn_host` aquí viven la clave pública del servidor, el endpoint y la
     * subred del túnel — nunca su clave privada.
     */
    public function capability(string $key, mixed $default = null): mixed
    {
        return data_get($this->capabilities, $key, $default);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeRole(Builder $query, AgentRole $role): Builder
    {
        return $query->where('role', $role->value);
    }

    /**
     * Agente `vpn_host` activo y con heartbeat reciente que debe atender los
     * pasos del lado del hosting. Si devuelve null, la saga no puede empezar.
     */
    public static function activeVpnHost(): ?self
    {
        return static::query()
            ->active()
            ->role(AgentRole::VPN_HOST)
            ->orderByDesc('last_seen_at')
            ->first();
    }

    public function toApiArray(): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'role'          => $this->role->value,
            'role_label'    => $this->role->label(),
            'is_active'     => $this->is_active,
            'is_online'     => $this->isOnline(),
            'enrolled'      => $this->enrolled_at !== null,
            'pending_enrollment' => $this->hasPendingEnrollment(),
            'agent_version' => $this->agent_version,
            'last_seen_at'  => $this->last_seen_at?->toIso8601String(),
            'last_ip'       => $this->last_ip,
            'capabilities'  => $this->publicCapabilities(),
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * `capabilities` puede crecer con datos operativos; al panel solo se exponen
     * los campos que describen el túnel, nunca rutas de ficheros ni internos.
     */
    private function publicCapabilities(): array
    {
        return array_filter([
            'interface'         => $this->capability('interface'),
            'endpoint_host'     => $this->capability('endpoint_host'),
            'endpoint_port'     => $this->capability('endpoint_port'),
            'subnet'            => $this->capability('subnet'),
            'server_public_key' => $this->capability('server_public_key'),
            'interfaces'        => $this->capability('provisioning_interfaces'),
        ], fn ($v) => $v !== null);
    }
}
