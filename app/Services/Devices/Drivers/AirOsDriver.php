<?php

namespace App\Services\Devices\Drivers;

use App\Enums\DeviceVendor;
use App\Models\NetworkDevice;
use App\Services\Devices\DeviceCapability;
use App\Services\Devices\DeviceDriver;
use App\Services\Devices\Dto\DeviceTelemetry;
use App\Services\Devices\Dto\ProbeResult;
use App\Services\Devices\Dto\RadioTelemetry;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
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
    /**
     * Prefijo del nombre de la cookie de sesión.
     *
     * No es un nombre fijo: airOS la llama `AIROS_` seguido de la MAC del
     * propio equipo sin separadores —`AIROS_FCECDA2C91C1`—, así que cambia en
     * cada antena. Buscar un nombre concreto, que es lo que hacía esto, no
     * encuentra nunca nada.
     */
    private const SESSION_PREFIX = 'AIROS_';
    private const STATUS_PATH    = '/status.cgi';

    /** Ver `request()`: sin esto, el firmware viejo del parque ni saluda. */
    private const CIPHERS = 'DEFAULT@SECLEVEL=1';

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
            airmaxQualityPercent:  $this->polling($wireless, 'quality'),
            airmaxCapacityPercent: $this->polling($wireless, 'capacity'),
            txRateMbps:      $this->float($wireless, 'txrate'),
            rxRateMbps:      $this->float($wireless, 'rxrate'),
            txThroughputKbps: $this->throughput($wireless, 'tx'),
            rxThroughputKbps: $this->throughput($wireless, 'rx'),
            txPowerDbm:      $this->int($wireless, 'txpower'),
            distanceM:       $this->int($wireless, 'distance'),
            stationCount:    $this->stationCount($wireless),
            security:        $this->str($wireless, 'security'),
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
     * Calidad y capacidad airMAX, que airOS publica dentro de `polling`.
     *
     * Se leen de ahí y no de la raíz porque es donde viven en las familias que
     * las publican; en las que no —un equipo con airMAX desactivado, o un
     * firmware que no las expone— el bloque no existe y el valor queda nulo, que
     * es lo correcto: «no lo informa» y «cero por ciento» no son lo mismo, y un
     * cero pintaría una alarma sobre un enlace sano.
     *
     * Se acota a 0-100 antes de devolverlo: la columna es un `tinyint` sin
     * signo y un firmware que devolviera algo fuera de rango tumbaría la
     * inserción del lote entero, no solo la de esa antena.
     *
     * @param array<string, mixed> $wireless
     */
    private function polling(array $wireless, string $key): ?int
    {
        $polling = $wireless['polling'] ?? null;

        if (!is_array($polling)) {
            return null;
        }

        $value = $this->int($polling, $key);

        return $value === null ? null : max(0, min(100, $value));
    }

    /**
     * Tráfico instantáneo en kbps del bloque `throughput`.
     *
     * Es el caudal que está cursando el enlace, no la tasa a la que negoció:
     * son las dos líneas del gráfico que dibuja la propia antena, y sin ellas no
     * hay forma de distinguir un enlace ocioso de uno saturado.
     *
     * @param array<string, mixed> $wireless
     */
    private function throughput(array $wireless, string $key): ?int
    {
        $throughput = $wireless['throughput'] ?? null;

        if (!is_array($throughput)) {
            return null;
        }

        $value = $this->int($throughput, $key);

        // Negativo no es un caudal: se descarta en vez de guardarse, porque la
        // columna no admite signo.
        return $value === null || $value < 0 ? null : $value;
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
     * ## El login de airOS no crea la sesión: valida una que ya existe
     *
     * Son tres peticiones y no dos, y el orden no es negociable. `login.cgi` no
     * emite una sesión nueva al autenticar: marca como válida la que el cliente
     * ya trae. El navegador la tiene porque al abrir la antena hizo un GET antes
     * de ver el formulario. Un cliente que va directo al POST no lleva ninguna,
     * así que no hay nada que validar y el equipo contesta sin `Set-Cookie` —
     * indistinguible, desde fuera, de una contraseña incorrecta.
     *
     * Sin la semilla el POST responde **302, como si hubiera funcionado**, y es
     * `status.cgi` quien luego rechaza la sesión. Comprobado contra una
     * NanoStation loco M5 con airOS 6.3.6: sin semilla, `status.cgi` devuelve
     * 302; con semilla, 200 y el JSON.
     *
     * El cuerpo va **urlencoded** aunque el formulario del equipo declare
     * `multipart/form-data`, y es al revés de lo que parece: el multipart que
     * construye Guzzle lo rechaza airOS 8 con un 400, mientras que el
     * urlencoded lo aceptan las dos generaciones. Medido sobre el parque:
     *
     *     .233 (airOS 8)  multipart POST=400  |  form POST=302 y JSON
     *     .247 (airOS 8)  multipart POST=400  |  form POST=302 y JSON
     *     .236 (airOS 6)  multipart POST=302  |  form POST=302 y JSON
     *
     * El 400 se ve luego como un 403 en `status.cgi`, que despista: parece un
     * problema de permisos y es un cuerpo que el httpd no supo leer.
     *
     * El bote de cookies se comparte entre las tres peticiones en vez de copiar
     * la cabecera a mano. Eso importa más de lo que parece: el nombre de la
     * cookie lleva dentro la MAC del equipo, así que no hay un nombre que
     * copiar.
     *
     * ## Cómo se sabe que salió bien
     *
     * Por lo que devuelve `status.cgi`, no por la cookie. Ante una sesión sin
     * autenticar airOS no responde 401: devuelve un 200 con el HTML del
     * formulario de acceso, o un 302 de vuelta a él. Fiarse del código de estado
     * daría por bueno un login fallido y el error saldría después, al parsear,
     * como «respuesta que no entiendo».
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
        $jar         = new CookieJar();

        // 1. Sembrar la sesión que el POST vendrá a validar.
        $semilla = $this->request($jar, $timeout)->get("{$base}/login.cgi");

        if (!$this->tieneSesion($jar)) {
            throw new \RuntimeException(
                "El equipo no abrió sesión en login.cgi (HTTP {$semilla->status()}); "
                . '¿es una antena airOS y es ese el puerto de su interfaz web?'
            );
        }

        // 2. Autenticar. `uri` es el campo oculto que lleva el formulario del
        //    equipo; hay firmwares que rechazan el POST si falta.
        $this->request($jar, $timeout)->asForm()->post("{$base}/login.cgi", [
            'username' => (string) $credentials['username'],
            'password' => (string) $credentials['password'],
            'uri'      => self::STATUS_PATH,
        ]);

        // 3. Leer, que es lo único que dice de verdad si la sesión vale.
        $status = $this->request($jar, $timeout)->get("{$base}" . self::STATUS_PATH);

        if ($status->redirect()) {
            throw new \RuntimeException('airOS devolvió al formulario de acceso: usuario o contraseña incorrectos.');
        }

        if (!$status->successful()) {
            throw new \RuntimeException("status.cgi devolvió HTTP {$status->status()}.");
        }

        if (!str_starts_with(ltrim($status->body()), '{')) {
            throw new \RuntimeException('airOS devolvió el formulario de acceso: usuario o contraseña incorrectos.');
        }

        return $status->json() ?? [];
    }

    /** ¿Abrió el equipo una sesión, se llame como se llame? */
    private function tieneSesion(CookieJar $jar): bool
    {
        foreach ($jar->toArray() as $cookie) {
            if (str_starts_with((string) ($cookie['Name'] ?? ''), self::SESSION_PREFIX)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Petición con el bote de cookies compartido y sin seguir redirecciones.
     *
     * Las redirecciones se cortan a propósito: el 302 hacia `login.cgi` es
     * precisamente la señal de que la sesión no vale, y seguirlo la borraría
     * devolviendo un 200 con el formulario.
     *
     * ## Por qué se baja el nivel de seguridad de OpenSSL
     *
     * Estas antenas llevan una década en la torre y su TLS es de entonces:
     * negocian Diffie-Hellman con claves de 1024 bits, que OpenSSL 3 rechaza de
     * plano en su nivel por defecto —`dh key too small`, sin llegar a hablar—.
     * Una LiteBeam M5 con airOS 6.2.0 del parque falla exactamente así.
     *
     * El nivel 1 es el mínimo que hace falta: admite esas claves y sigue
     * excluyendo lo que ya no vale nada. Se comprobó que el 0 no aporta nada.
     *
     * Y se aplica **solo aquí**, en las peticiones a las antenas: bajarlo
     * globalmente debilitaría también el TLS con el que la aplicación habla con
     * servicios de internet, que no tienen ninguna excusa para ir flojos.
     */
    private function request(CookieJar $jar, int $timeout): PendingRequest
    {
        return Http::withoutVerifying()
            ->timeout($timeout)
            ->withOptions([
                'cookies'         => $jar,
                'allow_redirects' => false,
                'curl'            => [CURLOPT_SSL_CIPHER_LIST => self::CIPHERS],
            ]);
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
