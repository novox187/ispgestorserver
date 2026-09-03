<?php

namespace App\Models;

use App\Enums\DeviceRole;
use App\Enums\DeviceVendor;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Cualquier equipo del parque, sea del fabricante que sea.
 *
 * Es la vista sin acotar de `network_devices`, la tabla que comparten los
 * routers MikroTik y las antenas Ubiquiti. `MikrotikRouter` mira esa misma tabla
 * a través de un scope global de fabricante y es la clase que usa todo el módulo
 * de MikroTik; esta es la que usan el inventario, el monitoreo y el mapa, que
 * necesitan ver el parque entero.
 *
 * Un solo inventario y no dos tablas paralelas porque la topología es un grafo:
 * `network_links` tiene que poder apuntar a cualquier extremo con una clave
 * foránea de verdad, y una MAC no puede estar duplicada entre fabricantes. Con
 * dos tablas ninguna de las dos cosas se puede garantizar en el motor.
 *
 * ## Cuidado al escribir por aquí
 *
 * Esta clase alcanza también las filas MikroTik. El invariante del router
 * primary lo sostiene `PrimaryRouterObserver`, registrado para ambos modelos
 * justo porque Eloquent despacha los eventos bajo la clase concreta: sin él,
 * borrar un router desde el inventario dejaría al sistema sin primary.
 */
class NetworkDevice extends Model
{
    use Auditable;

    protected $table = 'network_devices';

    /**
     * Una antena nace explícitamente sin plano de control. Sin esto el atributo
     * quedaría en `null` hasta releer la fila, y `null` invita a interpretarlo
     * como «no se sabe» cuando aquí sí se sabe.
     */
    protected $attributes = [
        'is_primary' => false,
    ];

    /** El equipo respondió y se le pudo leer el estado. */
    public const STATUS_CONNECTED = 'connected';

    /** Se le habló y no contestó: hay una incidencia real. */
    public const STATUS_DISCONNECTED = 'disconnected';

    /**
     * Nadie ha podido preguntarle últimamente, casi siempre porque su agente
     * está caído.
     *
     * Es un estado propio y no un `disconnected` por una razón práctica: si un
     * agente se cae, todos los equipos que vigilaba dejan de dar señales a la
     * vez. Tratar eso como trescientas caídas genera trescientas alertas que
     * entierran cualquier incidencia real y enseñan al operador a ignorar el
     * canal. «No lo sé» y «está caído» no son lo mismo.
     */
    public const STATUS_STALE = 'stale';

    public const STATUS_UNKNOWN = 'unknown';

    protected $fillable = [
        'name',
        'vendor',
        'role',
        'driver',
        'model',
        'firmware_version',
        'host',
        'port',
        'username',
        'password',
        'description',
        'is_active',
        'mac_address',
        'serial_number',
        'latitude',
        'longitude',
        'network_cidr',
        'gateway',
        'provisioning_source',
        'site_id',
        'client_id',
        'credential_profile_id',
        'agent_id',
        'is_monitored',
    ];

    /** Nunca sale en una respuesta de la API, igual que en `MikrotikRouter`. */
    protected $hidden = ['password'];

    protected $casts = [
        'vendor'               => DeviceVendor::class,
        'role'                 => DeviceRole::class,
        'is_active'            => 'boolean',
        'is_primary'           => 'boolean',
        'is_monitored'         => 'boolean',
        'port'                 => 'integer',
        'password'             => 'encrypted',
        'latitude'             => 'decimal:7',
        'longitude'            => 'decimal:7',
        'last_signal_dbm'      => 'integer',
        'last_ccq_percent'     => 'integer',
        'last_telemetry_at'    => 'datetime',
        'last_health_check_at' => 'datetime',
        'last_connected_at'    => 'datetime',
        'last_disconnected_at' => 'datetime',
        'consecutive_failures' => 'integer',
        'provisioned_at'       => 'datetime',
        // Propias del plano de control, pero declaradas aquí para que
        // `MikrotikRouter` herede la lista entera en vez de redeclararla y que
        // las dos se vayan separando con el tiempo.
        'last_loaded_at'       => 'datetime',
        'last_applied_at'      => 'datetime',
    ];

    /**
     * Igual que en `MikrotikRouter`: el monitoreo reescribe estos campos cada
     * pocos minutos y sin excluirlos el historial de cada equipo quedaría
     * sepultado bajo miles de filas sin valor de negocio.
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
            'last_signal_dbm',
            'last_ccq_percent',
            'last_telemetry_at',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeVendor(Builder $query, DeviceVendor|string $vendor): Builder
    {
        return $query->where('vendor', $vendor instanceof DeviceVendor ? $vendor->value : $vendor);
    }

    /**
     * Equipos que forman la red del ISP, excluyendo las antenas de cliente.
     *
     * Lo usan las alertas y el mapa: son dos órdenes de magnitud menos equipos y
     * son los que, al caer, dejan sin servicio a mucha gente a la vez.
     */
    public function scopeInfrastructure(Builder $query): Builder
    {
        return $query->whereIn(
            'role',
            array_map(fn (DeviceRole $r) => $r->value, DeviceRole::infrastructureCases()),
        );
    }

    /**
     * ¿Es este equipo un MikroTik, y por tanto tiene detrás una fila que
     * `MikrotikRouter` también ve?
     */
    public function isMikrotik(): bool
    {
        return $this->vendor === DeviceVendor::MIKROTIK;
    }

    public function vpnProfile(): HasOne
    {
        return $this->hasOne(RouterVpnProfile::class, 'router_id');
    }

    public function provisioningSessions(): HasMany
    {
        return $this->hasMany(DeviceProvisioningSession::class, 'router_id');
    }

    /**
     * Lugar físico donde está el equipo.
     *
     * Un equipo puede tener coordenadas propias, heredarlas de su sitio, o no
     * tener ninguna. El mapa resuelve esa precedencia; aquí solo vive la
     * relación.
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(NetworkSite::class, 'site_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(NetworkLink::class, 'a_device_id');
    }

    /**
     * Abonado al que pertenece el equipo. Null en la infraestructura.
     *
     * Es lo que separa el CPE del tejado de un cliente de la antena sectorial
     * de la torre: los dos son equipos y se sondean igual, pero solo del
     * primero tiene sentido preguntar «¿de quién es?» cuando algo se cae.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function credentialProfile(): BelongsTo
    {
        return $this->belongsTo(DeviceCredential::class, 'credential_profile_id');
    }

    /** Agente responsable de sondear este equipo. */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(ProvisioningAgent::class, 'agent_id');
    }

    public function metricSamples(): HasMany
    {
        return $this->hasMany(DeviceMetricSample::class, 'device_id');
    }

    public function hourlyMetrics(): HasMany
    {
        return $this->hasMany(DeviceMetricHourly::class, 'device_id');
    }

    /**
     * Usuario y contraseña con los que hablarle a este equipo.
     *
     * Manda lo que tenga el propio equipo y solo si no tiene nada se recurre al
     * perfil compartido. Ese orden importa: los routers dados de alta por el
     * flujo automático llevan una contraseña generada e individual, y un perfil
     * asignado por descuido no puede pisarla.
     *
     * @return array{username: ?string, password: ?string}
     */
    public function resolvedCredentials(): array
    {
        if (filled($this->username) && filled($this->getAttribute('password'))) {
            return [
                'username' => (string) $this->username,
                'password' => (string) $this->getAttribute('password'),
            ];
        }

        $profile = $this->credentialProfile;

        return [
            'username' => $profile?->username,
            'password' => $profile?->password,
        ];
    }

    /** Equipos que un agente concreto debe sondear. */
    public function scopeMonitoredBy(Builder $query, int $agentId): Builder
    {
        return $query->where('agent_id', $agentId)
            ->where('is_monitored', true)
            ->where('is_active', true);
    }
}
