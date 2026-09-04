<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DeviceRole;
use App\Enums\DeviceVendor;
use App\Http\Controllers\Controller;
use App\Models\DeviceMetricHourly;
use App\Models\DeviceMetricSample;
use App\Models\NetworkDevice;
use App\Models\NetworkLink;
use App\Services\Devices\ConnectivityRecorder;
use App\Services\Devices\DeviceCapability;
use App\Services\Devices\DeviceDriverRegistry;
use App\Services\Devices\TelemetryRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Inventario de equipos de red de cualquier fabricante.
 *
 * Convive con `MikrotikRouterController`, que sigue sirviendo al módulo de
 * MikroTik: aquel gobierna el plano de control —primary, credenciales de la API
 * de RouterOS, firewall— y este el parque entero, que es lo que necesitan el
 * monitoreo y el mapa.
 *
 * **Los routers MikroTik son de solo lectura por aquí.** Crear uno exige
 * decisiones que este controlador no toma (quién es el primary, qué pasa con el
 * túnel VPN) y que el alta automática ya resuelve. Dejar dos puertas de escritura
 * sobre la misma fila sería pedir que se separen.
 */
class NetworkDeviceController extends Controller
{
    public function __construct(
        private readonly DeviceDriverRegistry $drivers,
        private readonly ConnectivityRecorder $connectivity,
        private readonly TelemetryRecorder $telemetryRecorder,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $devices = NetworkDevice::query()
            // Una sola consulta más para todo el listado, no una por equipo: es
            // lo que permite que la tarjeta de monitoreo enseñe CPU, memoria y
            // caudal sin que el panel pague un N+1 sobre cientos de antenas.
            ->with('latestSample')
            ->when($request->filled('vendor'), fn ($q) => $q->where('vendor', $request->string('vendor')))
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->boolean('only_infrastructure'), fn ($q) => $q->infrastructure())
            ->orderBy('vendor')
            ->orderBy('name')
            ->get()
            ->map(fn (NetworkDevice $d) => $this->map($d));

        return response()->json(['data' => $devices]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json([
            'data' => $this->map(NetworkDevice::query()->with('latestSample')->findOrFail($id)),
        ]);
    }

    /**
     * Alta manual de un equipo por IP.
     *
     * Es la vía prevista para las antenas: el operador teclea dirección y
     * credenciales. El descubrimiento por barrido llega después y termina en
     * este mismo sitio.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        if ($validated['vendor'] === DeviceVendor::MIKROTIK->value) {
            return $this->mikrotikIsReadOnly();
        }

        $vendor = DeviceVendor::from($validated['vendor']);

        $validated['driver'] ??= $vendor->defaultDriver();
        // El formulario deja el puerto vacío cuando el operador no tiene por
        // qué saberlo, y ahí no vale caer en el `default` de la columna: es
        // 8728, el de la API de RouterOS, y dejaría la antena inalcanzable.
        $validated['port'] = $validated['port'] ?? $vendor->defaultPort();

        $device = NetworkDevice::create($validated);

        return response()->json(['data' => $this->map($device)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $device = NetworkDevice::findOrFail($id);

        if ($device->isMikrotik()) {
            return $this->mikrotikIsReadOnly();
        }

        $validated = $request->validate($this->rules(forUpdate: true));

        // No pisar la contraseña guardada si el formulario la envía vacía, que
        // es lo que hace un formulario de edición que no la muestra.
        if (blank($validated['password'] ?? null)) {
            unset($validated['password']);
        }

        // Vaciar el puerto en el formulario significa «el de siempre», no
        // «ninguno»: la columna no admite nulos y el `update` moriría con un
        // error de base de datos delante del operador.
        if (array_key_exists('port', $validated) && $validated['port'] === null) {
            $vendor = DeviceVendor::tryFrom((string) ($validated['vendor'] ?? $device->vendor?->value));
            $validated['port'] = $vendor?->defaultPort() ?? $device->port;
        }

        $device->update($validated);

        return response()->json(['data' => $this->map($device->fresh()->load('latestSample'))]);
    }

    public function destroy(int $id): JsonResponse
    {
        $device = NetworkDevice::findOrFail($id);

        if ($device->isMikrotik()) {
            return $this->mikrotikIsReadOnly();
        }

        $device->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Comprueba en el momento si el equipo responde con las credenciales dadas.
     *
     * Existe para que el operador no dé de alta una antena y se entere al día
     * siguiente, por una alerta, de que tecleó mal la contraseña. Solo funciona
     * si el servidor alcanza al equipo; cuando no, lo dirá el primer ciclo del
     * agente.
     *
     * ## Por qué un sondeo bueno se guarda y uno malo no
     *
     * Cuando responde, el equipo **está** vivo: no hay lectura alternativa de
     * una antena que acaba de entregar su telemetría, así que se anota y la
     * ficha deja de decir «Desconectado» delante de quien acaba de ver lo
     * contrario. Antes esa evidencia se tiraba y el rojo seguía ahí hasta el
     * siguiente ciclo del monitor.
     *
     * Cuando no responde, no se toca nada. Este botón se pulsa sobre todo
     * mientras se ajustan credenciales, y un intento fallido a propósito no
     * debería empujar al equipo hacia una alerta de caída. Decidir que algo está
     * caído es del monitor periódico, que para eso tiene un umbral de fallos
     * seguidos.
     */
    public function test(int $id): JsonResponse
    {
        $device = NetworkDevice::findOrFail($id);
        $driver = $this->drivers->for($device);

        if ($driver === null || !$driver->supports(DeviceCapability::PROBE)) {
            return response()->json([
                'error' => [
                    'code'    => 'DRIVER_UNAVAILABLE',
                    'message' => "No hay driver capaz de sondear «{$device->driver}».",
                ],
            ], 422);
        }

        $result = $driver->probe($device, 8);

        if ($result->ok) {
            $this->connectivity->recordUp($device, $result);
        }

        return response()->json(['data' => [
            'ok'       => $result->ok,
            'error'    => $result->error,
            'model'    => $result->model,
            'firmware' => $result->firmware,
            // Para que el panel pueda pintar el estado nuevo sin volver a pedir
            // la lista entera.
            'connectivity_status' => $device->fresh()?->connectivity_status,
        ]]);
    }

    /**
     * Ficha completa de un equipo: última lectura, historia y contexto.
     *
     * Existe aparte de `show` porque cuesta lo que cuesta: hasta cuatro
     * consultas más, una de ellas sobre la tabla de muestras, que es la grande
     * del sistema. Devolverlo en el listado significaría pagarlo por cada uno de
     * los cientos de equipos del parque para pintar tarjetas donde no cabe.
     *
     * ## Dos resoluciones, según lo que se pregunte
     *
     * Hasta 48 horas se sirve el detalle de `device_metric_samples`: es la
     * ventana en la que se diagnostica una incidencia en curso y ahí hacen falta
     * los cinco minutos. Más allá, el detalle ya ha sido podado —la retención
     * son unas dos semanas— y se sirve el resumen horario, que es lo que enseña
     * la tendencia de un enlace que se degrada poco a poco.
     *
     * El resumen horario trae mínimo y máximo además de la media a propósito: la
     * media sola miente justo en el caso interesante, porque un enlace que se
     * cae treinta segundos cada hora tiene una media excelente.
     */
    public function metrics(Request $request, int $id): JsonResponse
    {
        $device = NetworkDevice::query()
            ->with(['latestSample', 'client:id,full_name', 'site:id,name', 'agent:id,name,last_seen_at'])
            ->findOrFail($id);

        // Hasta 90 días: más atrás la retención del resumen horario sigue
        // habiendo datos, pero un gráfico con dos mil puntos no se lee.
        $hours = max(1, min(2160, (int) $request->integer('hours', 24)));
        $since = now()->subHours($hours);

        return response()->json(['data' => [
            'device'  => $this->map($device),
            'history' => $hours <= 48
                ? $this->sampleHistory($device, $since)
                : $this->hourlyHistory($device, $since),
            'peers'   => $this->peers($device),
            'context' => [
                'client' => $device->client === null ? null : [
                    'id'   => $device->client->id,
                    'name' => $device->client->full_name,
                ],
                'site' => $device->site === null ? null : [
                    'id'   => $device->site->id,
                    'name' => $device->site->name,
                ],
                'agent' => $device->agent === null ? null : [
                    'id'           => $device->agent->id,
                    'name'         => $device->agent->name,
                    'last_seen_at' => $device->agent->last_seen_at?->toIso8601String(),
                ],
            ],
        ]]);
    }

    /**
     * Serie al detalle, un punto por lectura.
     *
     * El tope de 600 puntos cubre 48 horas a una lectura cada cinco minutos con
     * margen. No se recorta por la mitad ni se promedia: si un día la cadencia
     * baja y no cabe, es preferible enseñar la ventana reciente completa que una
     * serie diezmada en la que ya no se ve el bache que se estaba buscando.
     *
     * @return array<string, mixed>
     */
    private function sampleHistory(NetworkDevice $device, \DateTimeInterface $since): array
    {
        $rows = DeviceMetricSample::query()
            ->where('device_id', $device->id)
            ->where('sampled_at', '>=', $since)
            ->orderByDesc('sampled_at')
            ->limit(600)
            ->get()
            ->reverse()
            ->values();

        return [
            'resolution' => 'sample',
            'points'     => $rows->map(fn (DeviceMetricSample $s) => [
                't'               => $s->sampled_at?->toIso8601String(),
                'signal'          => $s->signal_dbm,
                'signal_min'      => null,
                'signal_max'      => null,
                'noise'           => $s->noise_floor_dbm,
                'snr'             => $s->snr_db,
                'ccq'             => $s->ccq_percent,
                'cpu'             => $s->cpu_load_percent,
                'airmax_quality'  => $s->airmax_quality_percent,
                'airmax_capacity' => $s->airmax_capacity_percent,
                'tx_kbps'         => $s->tx_throughput_kbps,
                'rx_kbps'         => $s->rx_throughput_kbps,
            ])->all(),
        ];
    }

    /**
     * Serie agregada por hora: lo que sobrevive a la poda del detalle.
     *
     * @return array<string, mixed>
     */
    private function hourlyHistory(NetworkDevice $device, \DateTimeInterface $since): array
    {
        $rows = DeviceMetricHourly::query()
            ->where('device_id', $device->id)
            ->where('bucket_hour', '>=', $since)
            ->orderBy('bucket_hour')
            ->limit(2160)
            ->get();

        return [
            'resolution' => 'hourly',
            'points'     => $rows->map(fn (DeviceMetricHourly $h) => [
                't'               => $h->bucket_hour?->toIso8601String(),
                'signal'          => $h->signal_avg_dbm,
                'signal_min'      => $h->signal_min_dbm,
                'signal_max'      => $h->signal_max_dbm,
                'noise'           => null,
                'snr'             => $h->snr_avg_db,
                'ccq'             => $h->ccq_avg_percent,
                'cpu'             => $h->cpu_avg_percent,
                // El resumen horario no agrega estas dos: son de la serie al
                // detalle y ahí se quedan. Nulo es «esta resolución no lo
                // guarda», no «valió cero».
                'airmax_quality'  => null,
                'airmax_capacity' => null,
                'tx_kbps'         => null,
                'rx_kbps'         => null,
            ])->all(),
        ];
    }

    /**
     * Equipos al otro lado de los enlaces de este.
     *
     * Se miran los dos extremos porque `network_links` guarda cada enlace una
     * sola vez, con el id menor en `a_device_id`: filtrar solo por un lado
     * dejaría fuera la mitad de los vecinos de cualquier equipo.
     *
     * Los archivados no salen. Un enlace que se dejó de ver se conserva por si
     * su desaparición es la avería, pero en la ficha de diagnóstico solo estorba.
     *
     * @return list<array<string, mixed>>
     */
    private function peers(NetworkDevice $device): array
    {
        $links = NetworkLink::query()
            ->visible()
            ->with(['endpointA:id,name,host,role,connectivity_status', 'endpointB:id,name,host,role,connectivity_status'])
            ->where(fn ($q) => $q->where('a_device_id', $device->id)->orWhere('b_device_id', $device->id))
            ->get();

        return $links->map(function (NetworkLink $link) use ($device) {
            $other = $link->a_device_id === $device->id ? $link->endpointB : $link->endpointA;

            if ($other === null) {
                return null;
            }

            return [
                'link_id'             => $link->id,
                'device_id'           => $other->id,
                'name'                => $other->name,
                'host'                => $other->host,
                'role_label'          => $other->role?->label(),
                'connectivity_status' => $other->connectivity_status,
                'type'                => $link->type,
                'status'              => $link->status,
                'discovery_source'    => $link->discovery_source,
                'last_seen_at'        => $link->last_seen_at?->toIso8601String(),
            ];
        })->filter()->values()->all();
    }

    /**
     * Lee el equipo AHORA MISMO y devuelve lo que conteste.
     *
     * Es lo que hace que la ficha se parezca a la interfaz del propio equipo:
     * el ciclo de fondo sondea cada pocos minutos —cadencia pensada para
     * cientos de equipos a la vez— y eso, mirando UNO, se ve como una pantalla
     * congelada. Aquí se pregunta al abrir la ficha y mientras esté abierta.
     *
     * ## Qué se guarda
     *
     * La lectura entra en la serie como cualquier otra, pero truncada al
     * minuto: sondear cada pocos segundos no puede meter doce filas por minuto
     * en la tabla grande del sistema ni sesgar el resumen horario a favor de los
     * equipos que alguien estuvo mirando.
     *
     * ## Cuándo no funciona
     *
     * Cuando el servidor no alcanza al equipo, que es el caso de las antenas
     * que viven en la LAN del cliente y sondea un agente. Se devuelve el motivo
     * y la ficha sigue enseñando lo último que trajo el agente, en vez de
     * quedarse en blanco.
     */
    public function live(int $id): JsonResponse
    {
        $device = NetworkDevice::findOrFail($id);
        $driver = $this->drivers->for($device);

        if ($driver === null || !$driver->supports(DeviceCapability::TELEMETRY)) {
            return response()->json([
                'error' => [
                    'code'    => 'DRIVER_UNAVAILABLE',
                    'message' => "No hay driver capaz de leer «{$device->driver}» en directo.",
                ],
            ], 422);
        }

        /*
         * Cinco segundos y no los ocho del sondeo manual: esto se pide cada
         * pocos segundos con la ficha abierta, y un equipo que tarda más de
         * cinco en contestar no va a dar sensación de «en directo» de todos
         * modos. Cuando falle, la ficha se queda con lo que trajo el agente.
         */
        $telemetry = $driver->telemetry($device, 5);

        if (!$telemetry->reachable) {
            /*
             * 200 y no error: que el servidor no llegue a una antena de la LAN
             * del cliente es lo NORMAL, no una avería del panel. La ficha lo
             * dice una vez, se queda con los datos del agente y deja de insistir.
             */
            return response()->json(['data' => [
                'ok'        => false,
                'error'     => $telemetry->error,
                'telemetry' => null,
            ]]);
        }

        $this->telemetryRecorder->record($device, $telemetry);

        return response()->json(['data' => [
            'ok'        => true,
            'error'     => $telemetry->error,
            'telemetry' => $this->telemetry($device->fresh()?->latestSample),
            'device'    => $this->map($device->fresh()->load('latestSample')),
        ]]);
    }

    /** @return array<string, mixed> */
    private function rules(bool $forUpdate = false): array
    {
        $required = $forUpdate ? 'sometimes' : 'required';

        return [
            'name'     => [$required, 'string', 'max:100'],
            'vendor'   => [$required, Rule::enum(DeviceVendor::class)],
            'role'     => [$required, Rule::enum(DeviceRole::class)],
            'host'     => [$required, 'string', 'max:255'],
            'port'     => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:60'],
            'password' => ['nullable', 'string', 'max:255'],
            'driver'   => ['nullable', 'string', 'max:30'],
            'description'           => ['nullable', 'string', 'max:500'],
            'is_active'             => ['nullable', 'boolean'],
            'is_monitored'          => ['nullable', 'boolean'],
            'mac_address'           => ['nullable', 'string', 'max:17'],
            'serial_number'         => ['nullable', 'string', 'max:60'],
            'latitude'              => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'             => ['nullable', 'numeric', 'between:-180,180'],
            'agent_id'              => ['nullable', 'integer', 'exists:provisioning_agents,id'],
            'credential_profile_id' => ['nullable', 'integer', 'exists:device_credentials,id'],
        ];
    }

    private function mikrotikIsReadOnly(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code'    => 'MIKROTIK_MANAGED_ELSEWHERE',
                'message' => 'Los routers MikroTik se escriben por `/api/mikrotik-routers`, '
                    . 'que es donde vive su plano de control: credenciales, router '
                    . 'primary, CIDR de clientes y gateway.',
            ],
        ], 422);
    }

    /** @return array<string, mixed> */
    private function map(NetworkDevice $device): array
    {
        return [
            'id'                  => $device->id,
            'name'                => $device->name,
            'vendor'              => $device->vendor?->value,
            'vendor_label'        => $device->vendor?->label(),
            'role'                => $device->role?->value,
            'role_label'          => $device->role?->label(),
            'driver'              => $device->driver,
            'model'               => $device->model,
            'firmware_version'    => $device->firmware_version,
            'host'                => $device->host,
            'port'                => $device->port,
            'username'            => $device->username,
            'description'         => $device->description,
            'is_active'           => (bool) $device->is_active,
            'is_monitored'        => (bool) $device->is_monitored,
            'is_primary'          => (bool) $device->is_primary,
            'mac_address'         => $device->mac_address,
            'serial_number'       => $device->serial_number,
            'latitude'            => $device->latitude,
            'longitude'           => $device->longitude,
            'agent_id'            => $device->agent_id,
            'credential_profile_id' => $device->credential_profile_id,
            'has_radio'           => (bool) $device->role?->hasRadio(),
            'connectivity_status' => $device->connectivity_status,
            'last_signal_dbm'     => $device->last_signal_dbm,
            'last_ccq_percent'    => $device->last_ccq_percent,
            'last_telemetry_at'   => $device->last_telemetry_at?->toIso8601String(),
            // Cómo está configurado el enlace. Se sirve desde la ficha y no
            // desde la muestra porque no cambia de una lectura a otra.
            'ssid'                => $device->last_ssid,
            'wireless_mode'       => $device->last_wireless_mode,
            'wireless_mode_label' => $this->modeLabel($device->last_wireless_mode),
            'security'            => $device->last_security,
            'remote_mac'          => $device->last_remote_mac,
            'editable'            => !$device->isMikrotik(),
            /*
             * Nulo cuando la relación no se ha cargado y cuando el equipo aún no
             * tiene ninguna lectura. El panel distingue los dos casos por el
             * estado de conectividad, que sí está siempre.
             */
            'telemetry'           => $device->relationLoaded('latestSample')
                ? $this->telemetry($device->latestSample)
                : null,
        ];
    }

    /**
     * La última lectura, con todo lo que trajo.
     *
     * Se calculan aquí el SNR y el porcentaje de memoria en vez de dejarlo al
     * panel: son la misma cuenta en la tarjeta, en la ficha y en el mapa, y
     * repetirla en tres sitios es la forma habitual de que acaben discrepando.
     *
     * @return array<string, mixed>|null
     */
    private function telemetry(?DeviceMetricSample $sample): ?array
    {
        if ($sample === null) {
            return null;
        }

        $free  = $sample->memory_free_bytes;
        $total = $sample->memory_total_bytes;

        return [
            'sampled_at'         => $sample->sampled_at?->toIso8601String(),
            'uptime_seconds'     => $sample->uptime_seconds,
            'cpu_load_percent'   => $sample->cpu_load_percent,
            'memory_free_bytes'  => $free,
            'memory_total_bytes' => $total,
            'memory_used_percent' => ($total !== null && $total > 0 && $free !== null)
                ? round((($total - $free) / $total) * 100, 1)
                : null,
            'signal_dbm'         => $sample->signal_dbm,
            'noise_floor_dbm'    => $sample->noise_floor_dbm,
            'snr_db'             => $sample->snr_db,
            'ccq_percent'        => $sample->ccq_percent,
            'airmax_quality_percent'  => $sample->airmax_quality_percent,
            'airmax_capacity_percent' => $sample->airmax_capacity_percent,
            'tx_rate_mbps'       => $sample->tx_rate_mbps,
            'rx_rate_mbps'       => $sample->rx_rate_mbps,
            'tx_throughput_kbps' => $sample->tx_throughput_kbps,
            'rx_throughput_kbps' => $sample->rx_throughput_kbps,
            'tx_power_dbm'       => $sample->tx_power_dbm,
            'frequency_mhz'      => $sample->frequency_mhz,
            'channel_width_mhz'  => $sample->channel_width_mhz,
            'distance_m'         => $sample->distance_m,
            'station_count'      => $sample->station_count,
            /*
             * Que el equipo respondiera algo que el driver no supo leer es un
             * estado propio y hay que verlo: explica una tarjeta a medias sin
             * que parezca una avería. Se manda solo si existe, y recortado,
             * porque puede ser un JSON de miles de caracteres.
             */
            'unparsed' => $sample->unparsed_payload !== null,
        ];
    }

    /**
     * Nombre legible del modo inalámbrico.
     *
     * airOS lo publica en su jerga —`sta`, `ap-ptp`, `sta-wds`— y el operador la
     * conoce, pero la pantalla la ven también quienes no instalan antenas.
     */
    private function modeLabel(?string $mode): ?string
    {
        if ($mode === null) {
            return null;
        }

        return match (strtolower($mode)) {
            // airOS
            'sta'                     => 'Estación',
            'sta-wds', 'sta_wds'      => 'Estación WDS',
            'ap'                      => 'Punto de acceso',
            'ap-wds', 'ap_wds'        => 'Punto de acceso WDS',
            'ap-ptp'                  => 'Punto de acceso (PtP)',
            'ap-ptmp'                 => 'Punto de acceso (PtMP)',
            // RouterOS, que nombra los mismos papeles a su manera
            'station'                 => 'Estación',
            'station-bridge'          => 'Estación (puente)',
            'station-wds'             => 'Estación WDS',
            'station-pseudobridge'    => 'Estación (pseudopuente)',
            'station-pseudobridge-clone' => 'Estación (pseudopuente clonado)',
            'ap-bridge'               => 'Punto de acceso',
            'bridge'                  => 'Punto de acceso (un solo cliente)',
            default                   => $mode,
        };
    }
}
