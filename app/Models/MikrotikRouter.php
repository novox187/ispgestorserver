<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * Representa un dispositivo MikroTik registrado en el sistema.
 *
 * El **router primary** (un único registro con `is_primary=true`) es el que
 * usan por defecto todos los servicios del sistema cuando no se especifica un
 * `router_id`: `MikroTikServiceProvider`, sincronización de colas, firewall,
 * suspensiones, monitoreo. Es la única fuente de credenciales para conectarse
 * al RouterOS — el sistema ya no lee `MIKROTIK_*` de variables de entorno.
 *
 * Reglas automáticas en `booted()`:
 *  - El primer router creado se marca automáticamente como primary.
 *  - Cuando se marca un nuevo router como primary, el anterior se desmarca.
 *  - Si se elimina el primary, otro router toma su lugar automáticamente.
 */
class MikrotikRouter extends Model
{
    use Auditable;

    /**
     * Ids que este guardado despromovió, a la espera de auditarse en `saved`.
     *
     * Propiedad declarada y no atributo dinámico: así Eloquent no la confunde
     * con una columna e intenta persistirla.
     *
     * @var list<int>
     */
    public array $pendingPrimaryDemotions = [];

    /**
     * El monitor de conectividad reescribe los campos de salud cada 5 minutos
     * con `forceFill()->save()`, que sí dispara eventos Eloquent. Sin excluirlos
     * el trait generaría cientos de filas de auditoría al día por router y
     * ahogaría los cambios que sí importan (credenciales, host, primary).
     */
    protected static function auditIgnoredFields(): array
    {
        return [
            'connectivity_status',
            'last_health_check_at',
            'last_connected_at',
            'last_disconnected_at',
            'consecutive_failures',
            'last_loaded_at',
            'last_applied_at',
        ];
    }

    protected $fillable = [
        'name',
        'host',
        'port',
        'username',
        'password',
        'description',
        'is_active',
        'is_primary',
        'network_cidr',
        'gateway',
        'mac_address',
        'serial_number',
        'board_name',
        'routeros_version',
        'provisioning_source',
        'provisioned_at',
        'last_loaded_at',
        'last_applied_at',
        'connectivity_status',
        'last_health_check_at',
        'last_connected_at',
        'last_disconnected_at',
        'consecutive_failures',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'is_active'            => 'boolean',
        'is_primary'           => 'boolean',
        'port'                 => 'integer',
        'last_loaded_at'       => 'datetime',
        'last_applied_at'      => 'datetime',
        'last_health_check_at' => 'datetime',
        'last_connected_at'    => 'datetime',
        'last_disconnected_at' => 'datetime',
        'provisioned_at'       => 'datetime',
        'consecutive_failures' => 'integer',
        'password'             => 'encrypted',
    ];

    protected static function booted(): void
    {
        // El primer router creado pasa automáticamente a ser primary y activo.
        static::creating(function (self $router) {
            if (!static::query()->exists()) {
                $router->is_primary = true;
                if ($router->is_active === null) {
                    $router->is_active = true;
                }
            }
        });

        // Solo un router puede ser primary a la vez: cuando uno se marca como
        // tal, el anterior se desmarca dentro de la misma transacción.
        //
        // El update() es masivo y por tanto NO dispara eventos Eloquent: el
        // trait Auditable no ve la despromoción. Se registra a mano para que el
        // historial explique por qué un router dejó de ser primary — es un
        // cambio con consecuencias (todo el sistema pasa a operar contra otro
        // equipo) y sin esta línea no dejaría rastro alguno.
        static::saving(function (self $router) {
            if (!$router->is_primary || !$router->isDirty('is_primary')) {
                return;
            }

            $demoted = static::query()
                ->where('id', '!=', $router->id ?? 0)
                ->where('is_primary', true)
                ->pluck('id')
                ->all();

            if ($demoted === []) {
                return;
            }

            static::query()->whereIn('id', $demoted)->update(['is_primary' => false]);

            // La auditoría se aplaza a `saved`: en un alta, aquí el router que
            // promueve todavía no tiene id y el registro saldría sin decir
            // quién ocupó su lugar.
            $router->pendingPrimaryDemotions = $demoted;
        });

        static::saved(function (self $router) {
            foreach ($router->pendingPrimaryDemotions as $demotedId) {
                self::auditPrimaryDemotion($demotedId, $router);
            }

            $router->pendingPrimaryDemotions = [];
        });

        // Si se elimina el primary, promover otro (preferentemente activo) para
        // que el sistema no quede sin router por defecto.
        static::deleted(function (self $router) {
            if ($router->is_primary) {
                $replacement = static::query()
                    ->orderByDesc('is_active')
                    ->orderBy('id')
                    ->first();
                if ($replacement) {
                    $replacement->is_primary = true;
                    $replacement->saveQuietly();
                }
            }
        });
    }

    /**
     * Deja constancia de una despromoción que el update() masivo del hook
     * `saving` no puede registrar por sí solo. Igual que en el trait, un fallo
     * al auditar nunca rompe la operación de negocio que lo originó.
     */
    private static function auditPrimaryDemotion(int $demotedId, self $promoted): void
    {
        try {
            Audit::create([
                'table_name' => 'mikrotik_routers',
                'operation'  => 'PRIMARY_DEMOTED',
                'record_id'  => (string) $demotedId,
                'old_values' => ['is_primary' => true],
                'new_values' => [
                    'is_primary'         => false,
                    'promoted_router_id' => $promoted->id,
                    'promoted_router'    => $promoted->name,
                    'timestamp'          => now()->toIso8601String(),
                ],
                'user_id'    => Auth::id(),
                'user_type'  => Auth::user() ? get_class(Auth::user()) : null,
                'ip_address' => Request::ip() ?? '127.0.0.1',
            ]);
        } catch (\Throwable $e) {
            Log::error('MikrotikRouter: fallo al auditar la despromoción de primary.', [
                'demoted_id' => $demotedId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }

    /**
     * Atajo: el router que el sistema debe usar para operaciones sin router_id.
     */
    public static function primaryRouter(): ?self
    {
        return static::primary()->first();
    }

    public static function hasPrimary(): bool
    {
        return static::primary()->exists();
    }

    public function filterRules(): HasMany
    {
        return $this->hasMany(FirewallFilterRule::class, 'router_id');
    }

    public function natRules(): HasMany
    {
        return $this->hasMany(FirewallNatRule::class, 'router_id');
    }

    public function applyLogs(): HasMany
    {
        return $this->hasMany(FirewallApplyLog::class, 'router_id');
    }

    /**
     * Túnel WireGuard por el que el sistema alcanza a este equipo. Null en los
     * routers dados de alta a mano antes del aprovisionamiento automático.
     */
    public function vpnProfile(): HasOne
    {
        return $this->hasOne(RouterVpnProfile::class, 'router_id')
            ->whereIn('status', [
                RouterVpnProfile::STATUS_PENDING,
                RouterVpnProfile::STATUS_ACTIVE,
                RouterVpnProfile::STATUS_FAILED,
            ]);
    }

    public function provisioningSessions(): HasMany
    {
        return $this->hasMany(DeviceProvisioningSession::class, 'router_id');
    }

    /**
     * Localiza un equipo ya conocido a partir de lo que el agente detectó. El
     * serial manda sobre la MAC: sobrevive a cambios de puerto de gestión.
     */
    public static function findByIdentity(?string $serialNumber, ?string $macAddress): ?self
    {
        if ($serialNumber !== null) {
            $bySerial = static::query()->where('serial_number', $serialNumber)->first();
            if ($bySerial) {
                return $bySerial;
            }
        }

        if ($macAddress !== null) {
            return static::query()->where('mac_address', $macAddress)->first();
        }

        return null;
    }
}
