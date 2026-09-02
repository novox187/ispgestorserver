<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Un dispositivo MikroTik: la especialización de `NetworkDevice` que tiene plano
 * de control.
 *
 * El **router primary** (un único registro con `is_primary=true`) es el que usan
 * por defecto todos los servicios del sistema cuando no se especifica un
 * `router_id`: `MikroTikServiceProvider`, sincronización de colas, firewall,
 * suspensiones, monitoreo. Es la única fuente de credenciales para conectarse al
 * RouterOS — el sistema ya no lee `MIKROTIK_*` de variables de entorno. Las
 * reglas que sostienen ese invariante viven en `PrimaryRouterObserver`.
 *
 * ## Convivencia con otros fabricantes
 *
 * Desde que el inventario admite antenas Ubiquiti, todos los equipos comparten
 * la tabla `network_devices`. Esta clase sigue representando **solo los
 * MikroTik**: un scope global la acota a `vendor='mikrotik'`, de modo que las
 * decenas de servicios que consultan routers no tuvieron que cambiar ni una
 * línea y no pueden tropezarse con una antena por accidente.
 *
 * **Hereda de `NetworkDevice` y no es una clase hermana** por una razón muy
 * concreta: así un MikroTik *es* un dispositivo de red también para el sistema
 * de tipos. Cualquier servicio nuevo —drivers, monitoreo, notificaciones, mapa—
 * puede tipar sus firmas a `NetworkDevice` y aceptar routers y antenas por
 * igual. Con dos clases hermanas habría hecho falta duplicar cada firma.
 */
class MikrotikRouter extends NetworkDevice
{
    public const VENDOR = 'mikrotik';
    public const DRIVER = 'routeros';

    /**
     * Superficie de escritura propia: incluye `is_primary` y los campos del
     * plano de control, que `NetworkDevice` deja fuera a propósito porque no
     * significan nada para una antena.
     *
     * `vendor` sigue fuera: lo estampa `creating`, y cambiarlo por asignación
     * masiva convertiría un router en otra cosa a mitad de vida, dejando las
     * reglas de firewall y el perfil VPN apuntando a una fila que su propia
     * clase ya no ve.
     */
    protected $fillable = [
        'name',
        'role',
        'driver',
        'model',
        'firmware_version',
        'latitude',
        'longitude',
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



    protected static function booted(): void
    {
        parent::booted();

        /*
         * Acota la clase a los MikroTik de la tabla compartida. La columna va
         * cualificada con el nombre de la tabla porque cualquier consulta que
         * haga join con otra que también tenga `vendor` —`network_links`, por
         * ejemplo— haría la referencia ambigua y MySQL rechazaría la consulta.
         */
        static::addGlobalScope('vendor_mikrotik', function (Builder $query) {
            $query->where('network_devices.vendor', self::VENDOR);
        });

        /*
         * El scope global filtra lecturas, no inserciones: sin esto un router
         * recién creado nacería sin fabricante y quedaría invisible para su
         * propia clase nada más guardarse.
         */
        static::creating(function (self $router) {
            $router->vendor = self::VENDOR;
            $router->driver ??= self::DRIVER;
            $router->role   ??= 'core_router';
        });

        /*
         * Las reglas del router primary —el primero nace primary, solo hay uno,
         * y al borrarlo se promueve otro— NO viven aquí sino en
         * `PrimaryRouterObserver`, registrado para esta clase y para
         * `NetworkDevice`. Eloquent despacha los eventos bajo el nombre de la
         * clase concreta, así que un hook declarado aquí sería invisible para
         * las operaciones que entren por el modelo genérico, y bastaría con
         * borrar el primary desde el inventario para dejar el sistema sin router
         * por defecto sin un solo error en los logs.
         */
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
