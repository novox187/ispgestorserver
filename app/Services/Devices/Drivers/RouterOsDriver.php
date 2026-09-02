<?php

namespace App\Services\Devices\Drivers;

use App\Models\MikrotikRouter;
use App\Models\NetworkDevice;
use App\Services\Devices\DeviceCapability;
use App\Services\Devices\DeviceDriver;
use App\Services\Devices\Dto\DeviceTelemetry;
use App\Services\Devices\Dto\NeighborLink;
use App\Services\Devices\Dto\ProbeResult;
use App\Services\MikrotikHealthChecker;
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
        return match ($capability) {
            DeviceCapability::PROBE,
            DeviceCapability::TELEMETRY,
            DeviceCapability::NEIGHBORS => true,
            // La radio de los MikroTik inalámbricos llegará cuando haga falta;
            // el parque del cliente los usa como routers, no como antenas.
            DeviceCapability::RADIO,
            DeviceCapability::STATIONS  => false,
        };
    }

    public function probe(NetworkDevice $device, ?int $timeoutSeconds = null): ProbeResult
    {
        try {
            $resource = $this->checker->resources($device, $timeoutSeconds);
        } catch (Throwable $e) {
            return ProbeResult::down($e->getMessage());
        }

        return ProbeResult::up(
            model:         $this->str($resource, 'board-name'),
            firmware:      $this->str($resource, 'version'),
            uptimeSeconds: $this->parseUptime($this->str($resource, 'uptime')),
        );
    }

    public function telemetry(NetworkDevice $device, ?int $timeoutSeconds = null): DeviceTelemetry
    {
        try {
            $resource = $this->checker->resources($device, $timeoutSeconds);
        } catch (Throwable $e) {
            return DeviceTelemetry::unreachable($e->getMessage());
        }

        return $this->normalize($resource);
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
     * Traduce `/system/resource`. Un router de núcleo no tiene radio, así que
     * `radio` queda a null — que no es un enlace degradado, es una métrica que
     * no le aplica.
     */
    public function normalize(array $raw): DeviceTelemetry
    {
        return new DeviceTelemetry(
            reachable:        true,
            uptimeSeconds:    $this->parseUptime($this->str($raw, 'uptime')),
            cpuLoadPercent:   $this->int($raw, 'cpu-load') !== null
                ? (float) $this->int($raw, 'cpu-load')
                : null,
            memoryFreeBytes:  $this->int($raw, 'free-memory'),
            memoryTotalBytes: $this->int($raw, 'total-memory'),
            model:            $this->str($raw, 'board-name'),
            firmware:         $this->str($raw, 'version'),
        );
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
