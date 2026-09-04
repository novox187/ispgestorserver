<?php

namespace App\Services\Devices\Drivers;

use App\Models\MikrotikRouter;
use App\Models\NetworkDevice;
use App\Services\Devices\DeviceCapability;
use App\Services\Devices\DeviceDriver;
use App\Services\Devices\Dto\DeviceTelemetry;
use App\Services\Devices\Dto\NeighborLink;
use App\Services\Devices\Dto\ProbeResult;
use App\Services\Devices\Dto\RadioTelemetry;
use App\Services\MikrotikHealthChecker;
use RouterOS\Exceptions\BadCredentialsException;
use RouterOS\Exceptions\ConnectException;
use Throwable;

/**
 * Driver de los equipos MikroTik, sobre la API binaria de RouterOS.
 *
 * No reimplementa la conexión: delega en `MikrotikHealthChecker`, que ya crea un
 * cliente por fila y lleva tiempo en producción. Eso mantiene una sola forma de
 * hablar con un RouterOS y deja intactos los cinco archivos de test que mockean
 * esa clase.
 */
class RouterOsDriver implements DeviceDriver
{
    public function __construct(
        private readonly MikrotikHealthChecker $checker,
    ) {
    }

    public function vendor(): string
    {
        return MikrotikRouter::VENDOR;
    }

    public function name(): string
    {
        return MikrotikRouter::DRIVER;
    }

    public function supports(DeviceCapability $capability): bool
    {
        /*
         * La radio también. Se daba por hecho que los MikroTik del parque eran
         * routers, y el cliente tiene SXT y LHG haciendo de CPE inalámbrico: en
         * la ficha de uno de ellos la señal, el SNR y el CCQ salían vacíos
         * aunque el equipo los publicara, porque nadie se los pedía.
         */
        return match ($capability) {
            DeviceCapability::PROBE,
            DeviceCapability::TELEMETRY,
            DeviceCapability::NEIGHBORS,
            DeviceCapability::RADIO,
            DeviceCapability::STATIONS  => true,
        };
    }

    public function probe(NetworkDevice $device, ?int $timeoutSeconds = null): ProbeResult
    {
        try {
            $resource = $this->checker->resources($device, $timeoutSeconds);
        } catch (Throwable $e) {
            return ProbeResult::down($this->explicar($e, $device));
        }

        return ProbeResult::up(
            model:         $this->str($resource, 'board-name'),
            firmware:      $this->str($resource, 'version'),
            uptimeSeconds: $this->parseUptime($this->str($resource, 'uptime')),
        );
    }

    public function telemetry(NetworkDevice $device, ?int $timeoutSeconds = null): DeviceTelemetry
    {
        /*
         * La radio solo se pide a los equipos cuyo papel la tiene. Un router de
         * núcleo no la usa, y preguntarle por una tabla de registro que no
         * existe sería gastar un viaje por equipo y por ciclo para no leer nada.
         * Es la misma regla con la que el panel decide si enseñar dBm.
         */
        $conRadio = (bool) $device->role?->hasRadio();

        try {
            if (!$conRadio) {
                return $this->normalize($this->checker->resources($device, $timeoutSeconds));
            }

            // Los tres recursos van sobre UNA sesión: son tres comandos, no tres
            // logins contra un equipo de tejado.
            $lecturas = $this->checker->queries($device, [
                'resource'      => '/system/resource/print',
                'wireless'      => '/interface/wireless/print',
                'registrations' => '/interface/wireless/registration-table/print',
            ], $timeoutSeconds);
        } catch (Throwable $e) {
            return DeviceTelemetry::unreachable($this->explicar($e, $device));
        }

        $resource = is_array($lecturas['resource'][0] ?? null) ? $lecturas['resource'][0] : [];

        // Sin sistema no hay lectura: si eso falla, el equipo no contestó de
        // verdad, aunque los otros comandos hayan devuelto un array vacío.
        if ($resource === []) {
            return DeviceTelemetry::unreachable('el equipo no devolvió sus recursos de sistema');
        }

        return $this->normalize([
            'resource'      => $resource,
            'wireless'      => $lecturas['wireless'],
            'registrations' => $lecturas['registrations'],
        ]);
    }

    /**
     * Traduce el fallo a algo sobre lo que se pueda actuar.
     *
     * La biblioteca de RouterOS dice «Unable to establish socket session,
     * Operation timed out», que es cierto y no sirve de nada: no distingue un
     * equipo apagado de una IP que ya es de otro, ni de un servicio API sin
     * habilitar —que en un CPE de abonado viene desactivado de fábrica y es la
     * causa más frecuente—.
     *
     * Se apoya en los tipos de excepción y no en el texto, que cambia con la
     * versión de la biblioteca y con el idioma del sistema.
     */
    private function explicar(Throwable $e, NetworkDevice $device): string
    {
        $destino = $device->host . ':' . ($device->port ?: 8728);

        return match (true) {
            $e instanceof BadCredentialsException => 'RouterOS rechazó el usuario o la contraseña.',
            $e instanceof ConnectException => "No hay respuesta en {$destino}. Comprueba que el equipo "
                . 'esté encendido y en esa IP, y que tenga habilitado el servicio API '
                . '(IP → Services → api); en los equipos de abonado suele venir desactivado.',
            /*
             * «Socket timeout reached» es el texto de la biblioteca y no dice
             * nada al operador. Se traduce por el tipo de excepción no —no hay
             * uno propio— sino por el texto, aceptando que un cambio de versión
             * lo devuelva al mensaje crudo: es peor dejar inglés técnico donde
             * la pantalla espera una causa y una salida.
             */
            str_contains(strtolower($e->getMessage()), 'timeout')
                => "El equipo de {$destino} no contestó a tiempo. Suele ser que no hay ruta hasta él "
                . '—una antena en la red del cliente no es alcanzable desde el servidor— o que algo '
                . 'descarta los paquetes por el camino.',
            default => $e->getMessage(),
        };
    }

    /**
     * Lee `/ip/neighbor/print`.
     *
     * Es la fuente de topología más barata que hay: el router ya mantiene esa
     * tabla por su cuenta —descubre por MNDP, CDP y LLDP— y **es alcanzable
     * desde el servidor por el túnel WireGuard**, sin agente de por medio. Ahí
     * aparecen las antenas Ubiquiti, que hablan LLDP.
     *
     * @return list<NeighborLink>
     */
    public function neighbors(NetworkDevice $device, ?int $timeoutSeconds = null): array
    {
        try {
            $rows = $this->checker->query($device, '/ip/neighbor/print', $timeoutSeconds);
        } catch (Throwable $e) {
            // Un router que no responde no puede abortar el descubrimiento del
            // resto del parque.
            return [];
        }

        $neighbors = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $mac = $this->str($row, 'mac-address');

            if ($mac === null) {
                continue;
            }

            $neighbors[] = new NeighborLink(
                remoteMac:      strtoupper($mac),
                remoteIdentity: $this->str($row, 'identity'),
                remoteIp:       $this->str($row, 'address'),
                localInterface: $this->str($row, 'interface'),
                platform:       $this->str($row, 'platform'),
            );
        }

        return $neighbors;
    }

    /**
     * Traduce lo leído del equipo.
     *
     * Acepta las dos formas por compatibilidad: el array plano de
     * `/system/resource` —que es lo que sigue enviando cualquier llamada
     * antigua— y el compuesto con radio que arma `telemetry()`. Sin esa
     * tolerancia, cambiar la forma habría roto en silencio a quien ya llamaba a
     * `normalize()` con lo primero.
     *
     * Un router de núcleo sigue devolviendo `radio` a null, que no es un enlace
     * degradado: es una métrica que no le aplica.
     */
    public function normalize(array $raw): DeviceTelemetry
    {
        $compuesto = array_key_exists('resource', $raw);
        $resource  = $compuesto ? (array) $raw['resource'] : $raw;

        return new DeviceTelemetry(
            reachable:        true,
            uptimeSeconds:    $this->parseUptime($this->str($resource, 'uptime')),
            cpuLoadPercent:   $this->int($resource, 'cpu-load') !== null
                ? (float) $this->int($resource, 'cpu-load')
                : null,
            memoryFreeBytes:  $this->int($resource, 'free-memory'),
            memoryTotalBytes: $this->int($resource, 'total-memory'),
            model:            $this->str($resource, 'board-name'),
            firmware:         $this->str($resource, 'version'),
            radio:            $compuesto
                ? $this->radio((array) ($raw['wireless'] ?? []), (array) ($raw['registrations'] ?? []))
                : null,
        );
    }

    /**
     * Métricas de radio de un MikroTik inalámbrico.
     *
     * ## De dónde sale cada cosa
     *
     * La interfaz (`/interface/wireless`) dice cómo está **configurada** la
     * radio: SSID, banda, frecuencia, ancho y potencia. La tabla de registro
     * (`/interface/wireless/registration-table`) dice cómo va **el enlace ahora
     * mismo**: señal, relación señal/ruido, CCQ y velocidades negociadas. Hacen
     * falta las dos; ninguna sirve sola.
     *
     * ## Por qué la primera entrada y no una media
     *
     * En modo estación la tabla tiene exactamente una fila —el AP al que se
     * asocia—, que es el caso de un CPE. En modo AP tiene una por estación
     * asociada, y ahí una media de señales de veinte clientes no describe a
     * ninguno: se informa el número de estaciones, que sí significa algo, y se
     * toma la primera para las métricas del enlace.
     *
     * @param list<mixed> $interfaces
     * @param list<mixed> $registrations
     */
    private function radio(array $interfaces, array $registrations): ?RadioTelemetry
    {
        $wifi = null;

        foreach ($interfaces as $fila) {
            if (is_array($fila) && ($fila['disabled'] ?? 'false') !== 'true') {
                $wifi = $fila;
                break;
            }
        }

        $registros = array_values(array_filter($registrations, 'is_array'));

        // Sin interfaz ni registros no hay radio que informar. Devolver un objeto
        // con todo a null haría creer al panel que el equipo tiene radio y no
        // dice nada, cuando lo que pasa es que no la tiene.
        if ($wifi === null && $registros === []) {
            return null;
        }

        $enlace = $registros[0] ?? [];
        $modo   = $this->str($wifi ?? [], 'mode');

        return new RadioTelemetry(
            ssid:            $this->str($wifi ?? [], 'ssid'),
            mode:            $modo,
            frequencyMhz:    $this->int($wifi ?? [], 'frequency'),
            channelWidthMhz: $this->channelWidth($wifi ?? []),
            signalDbm:       $this->signal($enlace),
            // RouterOS publica la relación señal/ruido ya calculada; el ruido de
            // fondo no lo expone la tabla de registro, así que `noiseFloorDbm`
            // se queda nulo y el SNR NO se deriva restando: se toma tal cual.
            ccqPercent:      $this->ccq($enlace),
            txRateMbps:      $this->rate($this->str($enlace, 'tx-rate')),
            rxRateMbps:      $this->rate($this->str($enlace, 'rx-rate')),
            txPowerDbm:      $this->int($wifi ?? [], 'tx-power'),
            distanceM:       $this->distance($enlace),
            // En modo estación la única fila es el AP, no una estación asociada.
            stationCount:    $this->esEstacion($modo) ? null : count($registros),
            security:        $this->str($wifi ?? [], 'security-profile'),
            remoteMac:       $this->esEstacion($modo) ? $this->mac($enlace) : null,
            reportedSnrDb:   $this->int($enlace, 'signal-to-noise'),
            peerMacs:        $this->peerMacs($registros),
        );
    }

    /** @param list<array<string, mixed>> $registros @return list<string> */
    private function peerMacs(array $registros): array
    {
        $macs = [];

        foreach ($registros as $registro) {
            $mac = $this->mac($registro);

            if ($mac !== null) {
                $macs[] = $mac;
            }
        }

        return $macs;
    }

    private function mac(array $registro): ?string
    {
        $mac = $this->str($registro, 'mac-address');

        return $mac === null ? null : strtoupper($mac);
    }

    private function esEstacion(?string $modo): bool
    {
        return $modo !== null && str_starts_with($modo, 'station');
    }

    /**
     * `signal-strength` llega como `-67dBm@6Mbps` en unas versiones y como `-67`
     * en otras. Se lee el primer entero con signo y se ignora el resto.
     */
    private function signal(array $registro): ?int
    {
        $valor = $registro['signal-strength'] ?? $registro['signal-strength-ch0'] ?? null;

        if (is_numeric($valor)) {
            return (int) $valor;
        }

        return is_string($valor) && preg_match('/-?\d+/', $valor, $m) ? (int) $m[0] : null;
    }

    /**
     * CCQ del enlace.
     *
     * Se prefiere el de transmisión porque es el que describe lo que este equipo
     * consigue entregar —es también el que enseña airOS— y se cae al de
     * recepción cuando no está, que es lo que ocurre en modo estación de algunas
     * versiones.
     */
    private function ccq(array $registro): ?int
    {
        foreach (['tx-ccq', 'rx-ccq'] as $clave) {
            $valor = $this->int($registro, $clave);

            if ($valor !== null) {
                return max(0, min(100, $valor));
            }
        }

        return null;
    }

    /**
     * Las velocidades llegan como `54Mbps` o `130.0Mbps-2S-SGI`. Interesa el
     * número; el resto describe la modulación.
     */
    private function rate(?string $valor): ?float
    {
        if ($valor === null) {
            return null;
        }

        return preg_match('/[\d.]+/', $valor, $m) ? (float) $m[0] : null;
    }

    /** La distancia llega en metros o como `1.2km` según la versión. */
    private function distance(array $registro): ?int
    {
        $valor = $registro['distance'] ?? null;

        if (is_numeric($valor)) {
            return (int) $valor;
        }

        if (is_string($valor) && preg_match('/([\d.]+)\s*(km|m)?/i', $valor, $m)) {
            return (int) round(((float) $m[1]) * (strtolower($m[2] ?? 'm') === 'km' ? 1000 : 1));
        }

        return null;
    }

    /**
     * Ancho de canal.
     *
     * RouterOS no lo publica como número: va dentro de `channel-width`
     * (`20mhz`, `20/40mhz-Ce`) y, en las configuraciones que lo fijan en la
     * banda, dentro de `band` (`5ghz-onlyac/40-mhz`). Se mira primero el campo
     * propio y después la banda.
     *
     * Cuando no aparece se deja nulo en vez de suponer 20 MHz: un ancho
     * inventado se lee en el panel como una limitación real del enlace.
     *
     * @param array<string, mixed> $wifi
     */
    private function channelWidth(array $wifi): ?int
    {
        foreach (['channel-width', 'band'] as $clave) {
            $valor = $this->str($wifi, $clave);

            if ($valor !== null && preg_match('/(\d+)[\s-]*mhz/i', $valor, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * Convierte el uptime de RouterOS (`1w2d3h4m5s`) a segundos.
     *
     * El formato omite las unidades que valen cero, así que `3h20s` es legal y
     * hay que leerlo por pares número/unidad en vez de por posiciones fijas.
     */
    private function parseUptime(?string $uptime): ?int
    {
        if ($uptime === null || $uptime === '') {
            return null;
        }

        if (!preg_match_all('/(\d+)([wdhms])/', $uptime, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $factors = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
        $seconds = 0;

        foreach ($matches as [, $value, $unit]) {
            $seconds += ((int) $value) * $factors[$unit];
        }

        return $seconds;
    }

    /** @param array<string, mixed> $resource */
    private function str(array $resource, string $key): ?string
    {
        $value = $resource[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** @param array<string, mixed> $resource */
    private function int(array $resource, string $key): ?int
    {
        $value = $resource[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
