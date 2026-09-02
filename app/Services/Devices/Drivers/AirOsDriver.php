<?php

namespace App\Services\Devices\Drivers;

use App\Enums\DeviceVendor;
use App\Models\NetworkDevice;
use App\Services\Devices\DeviceCapability;
use App\Services\Devices\DeviceDriver;
use App\Services\Devices\Dto\DeviceTelemetry;
use App\Services\Devices\Dto\ProbeResult;
use App\Services\Devices\Dto\RadioTelemetry;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Driver de las antenas Ubiquiti airMAX (airOS): NanoStation, LiteBeam,
 * PowerBeam, Rocket, NanoBeam.
 *
 * ## Por qué HTTP y no SNMP
 *
 * SNMP sería más estable y de solo lectura por construcción, pero **viene
 * desactivado de fábrica en airOS**: exigirlo obligaría al cliente a entrar
 * antena por antena antes de ver un solo dato. La interfaz web siempre está
 * encendida. La puerta a SNMP queda abierta —sería otro driver— pero no se
 * implementa lo que no se va a usar.
 *
 * ## Quién habla con la antena
 *
 * Casi siempre el agente, porque las antenas viven en la LAN del cliente y el
 * servidor no llega. El agente se limita a traer el JSON de `status.cgi` y el
 * servidor lo interpreta aquí, en `normalize()`. Esa división es deliberada:
 * cuando aparezca un firmware que no sabemos leer, soportarlo es un despliegue
 * del servidor y no ir a la oficina a actualizar un demonio de Python.
 *
 * `probe()` y `telemetry()` hablan por HTTP directamente, para el caso en que la
 * antena sí sea alcanzable desde el servidor y para poder diagnosticar a mano.
 *
 * ## Qué cambia entre familias de firmware
 *
 * airOS 5.x/6.x (XW, WA) y 8.x (XC) publican el mismo JSON con diferencias de
 * detalle: el CCQ va en tanto por mil en unas y en porcentaje en otras, la
 * frecuencia llega como `"5805 MHz"` o como número, y la memoria cambia de
 * nombre. El parser tolera todas las variantes que conoce y, ante una que no,
 * devuelve `unparsed()` en vez de inventarse ceros — porque un cero en la señal
 * se lee como un enlace muerto.
 */
class AirOsDriver implements DeviceDriver
{
    public function vendor(): string
    {
        return DeviceVendor::UBIQUITI->value;
    }

    public function name(): string
    {
        return 'airos';
    }

    public function supports(DeviceCapability $capability): bool
    {
        return match ($capability) {
            DeviceCapability::PROBE,
            DeviceCapability::TELEMETRY,
            DeviceCapability::RADIO,
            DeviceCapability::STATIONS  => true,
            // airOS habla LLDP, pero leer su tabla de vecinos exige otra vía;
            // el descubrimiento de topología llega con el mapa.
            DeviceCapability::NEIGHBORS => false,
        };
    }

    public function probe(NetworkDevice $device, ?int $timeoutSeconds = null): ProbeResult
    {
        $telemetry = $this->telemetry($device, $timeoutSeconds);

        if (!$telemetry->reachable) {
            return ProbeResult::down($telemetry->error ?? 'sin respuesta');
        }

        return ProbeResult::up(
            model:         $telemetry->model,
            firmware:      $telemetry->firmware,
            uptimeSeconds: $telemetry->uptimeSeconds,
        );
    }

    public function telemetry(NetworkDevice $device, ?int $timeoutSeconds = null): DeviceTelemetry
    {
        try {
            $raw = $this->fetchStatus($device, $timeoutSeconds ?? 8);
        } catch (Throwable $e) {
            return DeviceTelemetry::unreachable($e->getMessage());
        }

        return $this->normalize($raw);
    }

    /**
     * airOS no expone su tabla de vecinos por esta vía.
     *
     * Sus enlaces se descubren por otro camino, y mejor: la lista de estaciones
     * asociadas que ya viene en `status.cgi` dice exactamente quién está al otro
     * lado de cada enlace inalámbrico, que es la información que el mapa
     * necesita. Ver `RadioTelemetry::$peerMacs`.
     *
     * @return list<\App\Services\Devices\Dto\NeighborLink>
     */
    public function neighbors(NetworkDevice $device, ?int $timeoutSeconds = null): array
    {
        return [];
    }

    /**
     * Traduce el JSON de `status.cgi`.
     *
     * @param array<string, mixed> $raw
     */
    public function normalize(array $raw): DeviceTelemetry
    {
        $host = $this->arr($raw, 'host');

        // Sin bloque `host` esto no es una respuesta de airOS que reconozcamos.
        // Se guarda el payload para poder darle soporte, y NO se marca el equipo
        // como caído: respondió, simplemente no le entendemos.
        if ($host === []) {
            return DeviceTelemetry::unparsed(
                'respuesta de airOS sin bloque «host» reconocible',
                $this->str($raw, 'fwversion'),
            );
        }

        $wireless = $this->arr($raw, 'wireless');

        return new DeviceTelemetry(
            reachable:        true,
            uptimeSeconds:    $this->int($host, 'uptime'),
            cpuLoadPercent:   $this->float($host, 'cpuload'),
            memoryFreeBytes:  $this->int($host, 'freeram'),
            memoryTotalBytes: $this->int($host, 'totalram'),
            model:            $this->str($host, 'devmodel') ?? $this->str($host, 'platform'),
            firmware:         $this->str($host, 'fwversion'),
            radio:            $wireless === [] ? null : $this->radio($wireless),
        );
    }

    /** @param array<string, mixed> $wireless */
    private function radio(array $wireless): RadioTelemetry
    {
        $mode = $this->str($wireless, 'mode');

        return new RadioTelemetry(
            ssid:            $this->str($wireless, 'essid'),
            mode:            $mode,
            frequencyMhz:    $this->frequency($wireless),
            channelWidthMhz: $this->int($wireless, 'chanbw'),
            signalDbm:       $this->int($wireless, 'signal'),
            noiseFloorDbm:   $this->int($wireless, 'noisef'),
            ccqPercent:      $this->ccq($wireless),
            txRateMbps:      $this->float($wireless, 'txrate'),
            rxRateMbps:      $this->float($wireless, 'rxrate'),
            txPowerDbm:      $this->int($wireless, 'txpower'),
            distanceM:       $this->int($wireless, 'distance'),
            stationCount:    $this->stationCount($wireless),
            // En modo estación la antena conoce la MAC del AP al que se asocia:
            // es la mitad de un enlace punto a punto y con ella el mapa puede
            // dibujarlo sin que nadie lo declare a mano.
            remoteMac:       $mode === 'sta' ? $this->str($wireless, 'apmac') : null,
            // Y en modo AP conoce la de cada estación asociada, que es la otra
            // mitad. Con las dos vías, cada enlace se corrobora desde sus dos
            // extremos.
            peerMacs:        $this->peerMacs($wireless, $mode),
        );
    }

    /**
     * MAC de los equipos que hay al otro lado de este enlace.
     *
     * @return list<string>
     */
    private function peerMacs(array $wireless, ?string $mode): array
    {
        if ($mode === 'sta') {
            $ap = $this->str($wireless, 'apmac');

            return $ap === null ? [] : [strtoupper($ap)];
        }

        $stations = $wireless['sta'] ?? null;

        if (!is_array($stations)) {
            return [];
        }

        $macs = [];

        foreach ($stations as $station) {
            $mac = is_array($station) ? ($station['mac'] ?? null) : null;

            if (is_string($mac) && $mac !== '') {
                $macs[] = strtoupper($mac);
            }
        }

        return $macs;
    }

    /**
     * airOS publica el CCQ en tanto por mil en unas familias (0-1000) y en
     * porcentaje en otras (0-100).
     *
     * Se distingue por el valor y no por la versión de firmware: la lista de
     * versiones envejecería mal, y un valor por encima de 100 solo puede ser
     * tanto por mil porque el porcentaje no puede pasar de ahí.
     */
    private function ccq(array $wireless): ?int
    {
        $value = $this->int($wireless, 'ccq');

        if ($value === null) {
            return null;
        }

        return $value > 100 ? (int) round($value / 10) : $value;
    }

    /**
     * La frecuencia llega como `"5805 MHz"` en unas familias y como número en
     * otras.
     */
    private function frequency(array $wireless): ?int
    {
        $value = $wireless['frequency'] ?? $wireless['freq'] ?? null;

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/(\d+)/', $value, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Número de estaciones asociadas cuando la antena hace de punto de acceso.
     *
     * Se prefiere contar la lista sobre fiarse del contador: algunas versiones
     * dejan `count` a cero con estaciones presentes.
     */
    private function stationCount(array $wireless): ?int
    {
        $stations = $wireless['sta'] ?? null;

        if (is_array($stations)) {
            return count($stations);
        }

        return $this->int($wireless, 'count');
    }

    /**
     * Autentica contra `/login.cgi` y lee `/status.cgi`.
     *
     * El certificado no se verifica porque es autofirmado por el propio equipo:
     * exigir una cadena válida haría imposible hablar con cualquier antena del
     * parque. La confidencialidad la aporta estar dentro de la red de gestión.
     *
     * @return array<string, mixed>
     */
    private function fetchStatus(NetworkDevice $device, int $timeout): array
    {
        $credentials = $device->resolvedCredentials();
        $base        = $this->baseUrl($device);

        $login = Http::withoutVerifying()
            ->timeout($timeout)
            ->asForm()
            ->withOptions(['allow_redirects' => false])
            ->post("{$base}/login.cgi", [
                'username' => (string) $credentials['username'],
                'password' => (string) $credentials['password'],
            ]);

        $cookies = $login->cookies();
        $session = $cookies?->getCookieByName('AIROS_SESSIONID')?->getValue();

        if ($session === null) {
            throw new \RuntimeException('airOS rechazó las credenciales o no devolvió sesión.');
        }

        $status = Http::withoutVerifying()
            ->timeout($timeout)
            ->withHeaders(['Cookie' => "AIROS_SESSIONID={$session}"])
            ->get("{$base}/status.cgi");

        if (!$status->successful()) {
            throw new \RuntimeException("status.cgi devolvió HTTP {$status->status()}.");
        }

        return $status->json() ?? [];
    }

    private function baseUrl(NetworkDevice $device): string
    {
        $port = (int) ($device->port ?: 443);
        $host = (string) $device->host;

        // 80 significa HTTP plano; cualquier otro puerto se asume TLS, que es lo
        // que airOS trae de fábrica.
        return $port === 80 ? "http://{$host}" : "https://{$host}:{$port}";
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function arr(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    private function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function float(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }
}
