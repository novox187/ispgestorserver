<?php

namespace App\Http\Controllers\Agent;

use App\Enums\AgentRole;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateProvisioningAgent;
use App\Jobs\EnrichScanWithNeighborsJob;
use App\Models\DeviceMetricSample;
use App\Enums\DeviceVendor;
use App\Models\NetworkDevice;
use App\Models\NetworkLink;
use App\Models\NetworkScan;
use App\Models\NetworkScanFinding;
use App\Models\ProvisioningAgent;
use App\Services\Devices\DeviceDriverRegistry;
use App\Services\Devices\TopologyRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Canal por el que un agente de monitoreo obtiene qué sondear y devuelve lo leído.
 *
 * Es un carril aparte de la cola `provisioning_tasks` a propósito. Aquella
 * modela una saga: cada tarea tiene compensación, vencimiento y una sesión de
 * alta detrás. La telemetría no es una saga sino un flujo continuo, y meterla
 * ahí habría significado una fila de tarea por antena y por ciclo —cientos de
 * miles al día— además de aflojar el `NOT NULL` de `session_id` del que depende
 * la integridad del alta automática.
 *
 * ## Frontera de seguridad
 *
 * `targets` entrega credenciales de equipos. Va acotado por `agent_id`: cada
 * agente recibe solo los suyos. Sin ese filtro, enrolar un agente en una torre
 * daría acceso a las claves de todo el parque del ISP, que es exactamente lo que
 * el modelo de roles existente se esfuerza en evitar.
 */
class MonitoringController extends Controller
{
    public function __construct(
        private readonly DeviceDriverRegistry $drivers,
        private readonly TopologyRecorder $topology,
    ) {
    }

    /** Tope de muestras por petición: acota el cuerpo y el coste de firmarlo. */
    private const MAX_SAMPLES_PER_BATCH = 200;

    /** Antigüedad máxima admitida en una muestra. */
    private const MAX_SAMPLE_AGE_HOURS = 24;

    /** Adelanto máximo admitido, para tolerar un reloj ligeramente adelantado. */
    private const MAX_SAMPLE_SKEW_MINUTES = 5;

    /**
     * GET /api/agent/monitoring/targets
     *
     * Equipos que este agente debe sondear, con sus credenciales ya resueltas.
     */
    public function targets(Request $request): JsonResponse
    {
        $agent = $this->agent($request);

        if ($agent->role !== AgentRole::MONITOR) {
            return $this->forbidden();
        }

        $devices = NetworkDevice::query()
            ->monitoredBy($agent->id)
            ->with('credentialProfile')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => [
                'poll_interval_seconds' => (int) config('devices.monitoring.poll_interval_seconds', 300),
                'targets' => $devices->map(function (NetworkDevice $device) {
                    $credentials = $device->resolvedCredentials();

                    return [
                        'device_id' => $device->id,
                        'name'      => $device->name,
                        'vendor'    => $device->vendor?->value,
                        'role'      => $device->role?->value,
                        'driver'    => $device->driver,
                        'host'      => $device->host,
                        'port'      => $device->port,
                        'username'  => $credentials['username'],
                        'password'  => $credentials['password'],
                    ];
                })->all(),
            ],
        ]);
    }

    /**
     * POST /api/agent/monitoring/samples
     *
     * Recibe un lote de lecturas. Es idempotente: reenviar el mismo lote tras un
     * timeout de red no duplica filas.
     */
    public function samples(Request $request): JsonResponse
    {
        $agent = $this->agent($request);

        if ($agent->role !== AgentRole::MONITOR) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'samples'                      => ['required', 'array', 'min:1', 'max:' . self::MAX_SAMPLES_PER_BATCH],
            'samples.*.device_id'          => ['required', 'integer'],
            // Epoch en segundos, no una cadena con zona: una cadena obliga a
            // interpretar el huso y ahí es donde se tuercen las series.
            'samples.*.sampled_at'         => ['required', 'integer'],
            'samples.*.reachable'          => ['required', 'boolean'],
            'samples.*.error'              => ['nullable', 'string', 'max:500'],
            'samples.*.uptime_seconds'     => ['nullable', 'integer', 'min:0'],
            'samples.*.cpu_load_percent'   => ['nullable', 'numeric', 'between:0,100'],
            'samples.*.memory_free_bytes'  => ['nullable', 'integer', 'min:0'],
            'samples.*.memory_total_bytes' => ['nullable', 'integer', 'min:0'],
            'samples.*.signal_dbm'         => ['nullable', 'integer', 'between:-120,0'],
            'samples.*.noise_floor_dbm'    => ['nullable', 'integer', 'between:-130,0'],
            'samples.*.ccq_percent'        => ['nullable', 'integer', 'between:0,100'],
            'samples.*.airmax_quality_percent'  => ['nullable', 'integer', 'between:0,100'],
            'samples.*.airmax_capacity_percent' => ['nullable', 'integer', 'between:0,100'],
            'samples.*.tx_rate_mbps'       => ['nullable', 'numeric', 'min:0'],
            'samples.*.rx_rate_mbps'       => ['nullable', 'numeric', 'min:0'],
            'samples.*.tx_throughput_kbps' => ['nullable', 'integer', 'min:0'],
            'samples.*.rx_throughput_kbps' => ['nullable', 'integer', 'min:0'],
            'samples.*.tx_power_dbm'       => ['nullable', 'integer', 'between:-10,40'],
            'samples.*.frequency_mhz'      => ['nullable', 'integer', 'min:0'],
            'samples.*.channel_width_mhz'  => ['nullable', 'integer', 'min:0'],
            'samples.*.distance_m'         => ['nullable', 'integer', 'min:0'],
            'samples.*.station_count'      => ['nullable', 'integer', 'min:0'],
            'samples.*.unparsed_payload'   => ['nullable', 'string', 'max:4000'],
            /*
             * Respuesta cruda del equipo, para los fabricantes cuyo parseo vive
             * en el servidor. El agente de airOS se limita a traer el JSON de
             * `status.cgi` y aquí se interpreta: así, soportar un firmware nuevo
             * es un despliegue y no una visita a la oficina del cliente para
             * actualizar el agente a mano.
             */
            'samples.*.raw'                => ['nullable', 'array'],
        ]);

        // Un agente solo puede reportar sobre los equipos que tiene asignados.
        $ownedIds = NetworkDevice::query()
            ->monitoredBy($agent->id)
            ->pluck('id')
            ->flip();

        $now      = CarbonImmutable::now();
        $oldest   = $now->subHours(self::MAX_SAMPLE_AGE_HOURS);
        $newest   = $now->addMinutes(self::MAX_SAMPLE_SKEW_MINUTES);

        $rows     = [];
        $latest   = [];
        $rejected = [];

        $devices = NetworkDevice::query()->whereKey($ownedIds->keys())->get()->keyBy('id');

        foreach ($validated['samples'] as $sample) {
            $deviceId = (int) $sample['device_id'];

            if (!$ownedIds->has($deviceId)) {
                $rejected[] = ['device_id' => $deviceId, 'reason' => 'NOT_ASSIGNED'];
                continue;
            }

            if (isset($sample['raw'])) {
                $sample = $this->normalizeRaw($devices[$deviceId], $sample);
            }

            $sampledAt = CarbonImmutable::createFromTimestampUTC((int) $sample['sampled_at']);

            /*
             * Una muestra fuera de ventana se descarta en vez de guardarse. Un
             * agente con el reloj roto podría envenenar meses de serie con
             * lecturas fechadas en 1970 o en 2031, y desde una gráfica no hay
             * forma de distinguir eso de un dato bueno.
             */
            if ($sampledAt->lt($oldest) || $sampledAt->gt($newest)) {
                $rejected[] = ['device_id' => $deviceId, 'reason' => 'TIMESTAMP_OUT_OF_RANGE'];
                continue;
            }

            $rows[] = $this->row($deviceId, $sampledAt, $sample);

            // Del lote de un mismo equipo solo la más reciente actualiza el
            // resumen que ven el listado y el mapa.
            if (!isset($latest[$deviceId]) || $sampledAt->gt($latest[$deviceId]['at'])) {
                $latest[$deviceId] = ['at' => $sampledAt, 'sample' => $sample];
            }
        }

        $stored = 0;

        if ($rows !== []) {
            // `insertOrIgnore` sobre el índice único (device_id, sampled_at):
            // reenviar un lote que ya se guardó no duplica ni falla.
            $stored = DB::table('device_metric_samples')->insertOrIgnore($rows);

            $this->refreshSnapshots($latest);
        }

        return response()->json([
            'data' => [
                'received' => count($validated['samples']),
                'stored'   => $stored,
                'rejected' => $rejected,
            ],
        ]);
    }

    /**
     * Convierte una respuesta cruda en los campos normalizados de la muestra.
     *
     * Si el driver no sabe interpretarla, la muestra NO se marca como caída: el
     * equipo respondió, simplemente hablaba un dialecto que aún no leemos. El
     * payload se guarda para poder darle soporte después. Confundir «no lo
     * entiendo» con «está muerto» llenaría el panel de alarmas falsas cada vez
     * que el cliente actualizara el firmware de una tanda de antenas.
     *
     * @param array<string, mixed> $sample
     * @return array<string, mixed>
     */
    private function normalizeRaw(NetworkDevice $device, array $sample): array
    {
        $driver = $this->drivers->for($device);

        if ($driver === null) {
            $sample['unparsed_payload'] = mb_substr(json_encode($sample['raw']) ?: '', 0, 4000);
            unset($sample['raw']);

            return $sample;
        }

        $telemetry = $driver->normalize($sample['raw']);

        /*
         * Los enlaces inalámbricos se descubren AQUÍ y no en el worker de
         * topología porque es el único sitio donde llega esa información: las
         * antenas viven en la LAN del cliente y el servidor no puede
         * preguntarles. La respuesta ya trae quién está al otro lado —el AP si
         * somos estación, las estaciones asociadas si somos AP—, así que
         * aprovecharlo no cuesta ninguna consulta extra.
         */
        if ($telemetry->radio !== null && $telemetry->radio->peerMacs !== []) {
            $this->topology->recordPeers(
                $device,
                $telemetry->radio->peerMacs,
                NetworkLink::SOURCE_AIROS_STATION,
                'wireless_ptp',
            );
        }

        if ($telemetry->error !== null) {
            $sample['unparsed_payload'] = mb_substr(json_encode($sample['raw']) ?: '', 0, 4000);
            unset($sample['raw']);

            return $sample;
        }

        $radio = $telemetry->radio;

        $normalized = array_filter([
            'uptime_seconds'     => $telemetry->uptimeSeconds,
            'cpu_load_percent'   => $telemetry->cpuLoadPercent,
            'memory_free_bytes'  => $telemetry->memoryFreeBytes,
            'memory_total_bytes' => $telemetry->memoryTotalBytes,
            'signal_dbm'         => $radio?->signalDbm,
            'noise_floor_dbm'    => $radio?->noiseFloorDbm,
            'ccq_percent'        => $radio?->ccqPercent,
            'airmax_quality_percent'  => $radio?->airmaxQualityPercent,
            'airmax_capacity_percent' => $radio?->airmaxCapacityPercent,
            'tx_rate_mbps'       => $radio?->txRateMbps,
            'rx_rate_mbps'       => $radio?->rxRateMbps,
            'tx_throughput_kbps' => $radio?->txThroughputKbps,
            'rx_throughput_kbps' => $radio?->rxThroughputKbps,
            'tx_power_dbm'       => $radio?->txPowerDbm,
            'frequency_mhz'      => $radio?->frequencyMhz,
            'channel_width_mhz'  => $radio?->channelWidthMhz,
            'distance_m'         => $radio?->distanceM,
            'station_count'      => $radio?->stationCount,
            /*
             * Estas cuatro no van a la serie temporal: describen cómo está
             * configurado el enlace y no cambian entre una lectura y la
             * siguiente. `row()` las ignora a propósito y solo las recoge
             * `refreshSnapshots()`, que las sobrescribe en la ficha del equipo.
             */
            'ssid'               => $radio?->ssid,
            'wireless_mode'      => $radio?->mode,
            'security'           => $radio?->security,
            'remote_mac'         => $radio?->remoteMac,
        ], fn ($v) => $v !== null);

        unset($sample['raw']);

        // Lo que ya venga normalizado del agente manda: si un día un agente
        // sabe leer su equipo mejor que nosotros, no se le pisa.
        return array_merge($normalized, array_filter($sample, fn ($v) => $v !== null));
    }

    /**
     * Actualiza en la fila del equipo lo último que se sabe de él.
     *
     * Desnormalizado a propósito: el listado y el mapa piden el dato más reciente
     * de cientos de equipos a la vez, y sacarlo de la tabla de muestras exigiría
     * una subconsulta por fila para un valor que se sobrescribe en cada ciclo.
     *
     * Aquí se lleva también la cuenta de fallos consecutivos, porque es aquí
     * donde se sabe si el equipo respondió. Quien decide si eso merece una
     * alerta es `MonitorDeviceConnectivityJob`: este canal registra hechos, no
     * juicios, y así el umbral se aplica en un único sitio para los equipos que
     * sondea un agente y para los que sondea el servidor.
     *
     * @param array<int, array{at: CarbonImmutable, sample: array<string, mixed>}> $latest
     */
    private function refreshSnapshots(array $latest): void
    {
        $devices = NetworkDevice::query()->whereKey(array_keys($latest))->get();

        foreach ($devices as $device) {
            $entry  = $latest[$device->id];
            $sample = $entry['sample'];

            $device->forceFill([
                'last_telemetry_at'    => $entry['at'],
                'last_health_check_at' => $entry['at'],
                'last_signal_dbm'      => $sample['signal_dbm']  ?? null,
                'last_ccq_percent'     => $sample['ccq_percent'] ?? null,
            ]);

            /*
             * La identidad del enlace se sobrescribe solo cuando la lectura la
             * trae. Una muestra que el driver no supo interpretar, o la de un
             * equipo sin radio, no puede borrar el SSID que ya se conocía:
             * dejaría la ficha en blanco justo cuando hay una avería que
             * diagnosticar, que es cuando alguien la mira.
             */
            foreach ([
                'last_ssid'          => 'ssid',
                'last_wireless_mode' => 'wireless_mode',
                'last_security'      => 'security',
                'last_remote_mac'    => 'remote_mac',
            ] as $columna => $clave) {
                if (($sample[$clave] ?? null) !== null) {
                    $device->forceFill([$columna => $sample[$clave]]);
                }
            }

            if ($sample['reachable']) {
                $device->forceFill([
                    'connectivity_status'  => NetworkDevice::STATUS_CONNECTED,
                    'last_connected_at'    => $entry['at'],
                    'consecutive_failures' => 0,
                ]);
            } else {
                $device->forceFill([
                    'consecutive_failures' => ((int) $device->consecutive_failures) + 1,
                ]);
            }

            $device->save();
        }
    }

    /**
     * @param array<string, mixed> $sample
     * @return array<string, mixed>
     */
    private function row(int $deviceId, CarbonImmutable $sampledAt, array $sample): array
    {
        $signal = $sample['signal_dbm']      ?? null;
        $noise  = $sample['noise_floor_dbm'] ?? null;

        return [
            'device_id'          => $deviceId,
            'sampled_at'         => $sampledAt->toDateTimeString(),
            'uptime_seconds'     => $sample['uptime_seconds']     ?? null,
            'cpu_load_percent'   => $sample['cpu_load_percent']   ?? null,
            'memory_free_bytes'  => $sample['memory_free_bytes']  ?? null,
            'memory_total_bytes' => $sample['memory_total_bytes'] ?? null,
            'signal_dbm'         => $signal,
            'noise_floor_dbm'    => $noise,
            // Se calcula al guardar en vez de pedírselo al agente: no todos los
            // firmwares lo publican, pero casi todos dan señal y ruido, y así la
            // columna está poblada de forma homogénea para las gráficas.
            'snr_db'             => ($signal !== null && $noise !== null) ? $signal - $noise : null,
            'ccq_percent'        => $sample['ccq_percent']       ?? null,
            'airmax_quality_percent'  => $sample['airmax_quality_percent']  ?? null,
            'airmax_capacity_percent' => $sample['airmax_capacity_percent'] ?? null,
            'tx_rate_mbps'       => $sample['tx_rate_mbps']      ?? null,
            'rx_rate_mbps'       => $sample['rx_rate_mbps']      ?? null,
            'tx_throughput_kbps' => $sample['tx_throughput_kbps'] ?? null,
            'rx_throughput_kbps' => $sample['rx_throughput_kbps'] ?? null,
            'tx_power_dbm'       => $sample['tx_power_dbm']      ?? null,
            'frequency_mhz'      => $sample['frequency_mhz']     ?? null,
            'channel_width_mhz'  => $sample['channel_width_mhz'] ?? null,
            'distance_m'         => $sample['distance_m']        ?? null,
            'station_count'      => $sample['station_count']     ?? null,
            'unparsed_payload'   => $sample['unparsed_payload']  ?? null,
        ];
    }

    /**
     * GET /api/agent/monitoring/scans
     *
     * Barridos que el operador ha pedido para este agente.
     */
    public function scans(Request $request): JsonResponse
    {
        $agent = $this->agent($request);

        if ($agent->role !== AgentRole::MONITOR) {
            return $this->forbidden();
        }

        $scans = NetworkScan::query()
            ->where('agent_id', $agent->id)
            ->pending()
            ->orderBy('id')
            ->limit(5)
            ->get();

        // Se marcan como en curso al entregarlos: así una segunda vuelta del
        // agente no vuelve a barrer lo mismo, y un barrido que nunca se reporte
        // queda visible como colgado en vez de repetirse para siempre.
        NetworkScan::query()
            ->whereKey($scans->pluck('id'))
            ->update(['status' => NetworkScan::STATUS_RUNNING, 'started_at' => now()]);

        return response()->json([
            'data' => [
                'scans' => $scans->map(fn (NetworkScan $s) => [
                    'id'   => $s->id,
                    'cidr' => $s->cidr,
                ])->all(),
            ],
        ]);
    }

    /**
     * POST /api/agent/monitoring/scans/{id}/report
     *
     * Resultado de un barrido. Los hallazgos son CANDIDATOS: no entran al
     * inventario hasta que un operador los confirma. Un barrido ve impresoras,
     * portátiles y el equipo del vecino, y volcarlo todo llenaría el mapa de
     * ruido que después habría que limpiar a mano.
     */
    public function reportScan(Request $request, int $id): JsonResponse
    {
        $agent = $this->agent($request);

        if ($agent->role !== AgentRole::MONITOR) {
            return $this->forbidden();
        }

        $scan = NetworkScan::query()->where('agent_id', $agent->id)->find($id);

        if ($scan === null) {
            return response()->json([
                'error' => ['code' => 'SCAN_NOT_FOUND', 'message' => 'Ese barrido no es de este agente.'],
            ], 404);
        }

        $validated = $request->validate([
            'status'                  => ['required', 'string', 'in:completed,failed'],
            'findings'                => ['nullable', 'array', 'max:1024'],
            'findings.*.ip_address'   => ['required', 'ip'],
            'findings.*.mac_address'  => ['nullable', 'string', 'max:32'],
            'findings.*.model'        => ['nullable', 'string', 'max:60'],
            // 80 y no 40: las versiones de airOS llevan plataforma, chipset,
            // compilación y fecha —«XW.ar934x.v6.1.7-licensed.32555.180523.1625»
            // son 43— y con el límite anterior se perdía el barrido ENTERO por
            // unas pocas antenas, porque el 422 rechaza el informe completo.
            'findings.*.firmware'     => ['nullable', 'string', 'max:80'],
            'findings.*.hostname'     => ['nullable', 'string', 'max:100'],
            'findings.*.essid'        => ['nullable', 'string', 'max:64'],
            'error_code'              => ['nullable', 'string', 'max:60'],
            'error_message'           => ['nullable', 'string', 'max:1000'],
        ]);

        $guardados = 0;

        foreach ($validated['findings'] ?? [] as $finding) {
            $mac = $this->normalizeMac($finding['mac_address'] ?? null);

            // Se marca lo que ya está en el inventario en vez de omitirlo: al
            // operador le sirve ver que su barrido encontró lo que esperaba, y
            // no solo lo que le falta.
            $conocido = $mac === null
                ? NetworkDevice::query()->where('host', $finding['ip_address'])->first()
                : NetworkDevice::query()->where('mac_address', $mac)
                    ->orWhere('host', $finding['ip_address'])->first();

            NetworkScanFinding::query()->updateOrCreate(
                ['scan_id' => $scan->id, 'ip_address' => $finding['ip_address']],
                [
                    'mac_address'       => $mac,
                    'vendor'            => DeviceVendor::fromMacAddress($mac)?->value,
                    'model'             => $finding['model']    ?? null,
                    'firmware'          => $finding['firmware'] ?? null,
                    'hostname'          => $finding['hostname'] ?? null,
                    'essid'             => $finding['essid']    ?? null,
                    'matched_device_id' => $conocido?->id,
                    'created_at'        => now(),
                ],
            );

            $guardados++;
        }

        $scan->update([
            'status'        => $validated['status'],
            'finished_at'   => now(),
            'found_count'   => $guardados,
            'error_code'    => $validated['error_code']    ?? null,
            'error_message' => $validated['error_message'] ?? null,
        ]);

        // El barrido del agente solo ve equipos airOS: los MikroTik hablan MNDP,
        // que no cruza un enlace enrutado. La otra mitad del parque sale de las
        // tablas de vecinos de los routers, y esa consulta la hace el servidor.
        //
        // Va en cola porque puede tardar segundos por router y el agente está
        // esperando esta respuesta para seguir su vuelta. Se dispara también en
        // caso de fallo: si el agente rechazó el rango, la tabla de vecinos es
        // lo único que le queda al operador.
        EnrichScanWithNeighborsJob::dispatch($scan->id);

        return response()->json(['data' => ['stored' => $guardados]]);
    }

    /**
     * Normaliza a AA:BB:CC:DD:EE:FF, igual que hace la detección física: sin
     * esto el mismo equipo no cruzaría con su fila del inventario por venir en
     * minúsculas o con guiones.
     */
    private function normalizeMac(?string $mac): ?string
    {
        if ($mac === null) {
            return null;
        }

        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac) ?? '');

        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : null;
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code'    => 'AGENT_ROLE_FORBIDDEN',
                'message' => 'Solo un agente de monitoreo puede usar este canal.',
            ],
        ], 403);
    }

    private function agent(Request $request): ProvisioningAgent
    {
        /** @var ProvisioningAgent $agent */
        $agent = $request->attributes->get(AuthenticateProvisioningAgent::REQUEST_ATTRIBUTE);

        return $agent;
    }
}
