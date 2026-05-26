<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        static::saving(function (self $router) {
            if ($router->is_primary && $router->isDirty('is_primary')) {
                static::query()
                    ->where('id', '!=', $router->id ?? 0)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }
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
}
