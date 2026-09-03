<?php

namespace App\Services\Devices;

use App\Enums\DeviceVendor;
use App\Models\NetworkDevice;
use App\Models\NetworkScan;
use App\Models\NetworkScanFinding;
use App\Services\Devices\Dto\NeighborLink;
use App\Support\CidrRange;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Completa un barrido con lo que ven las tablas de vecinos de los routers.
 *
 * ## Por qué hacen falta dos fuentes
 *
 * El barrido del agente habla el protocolo de descubrimiento de Ubiquiti (UDP
 * 10001), que **solo contestan los equipos airOS**. Los MikroTik usan MNDP, que
 * es propietario y además solo se emite por difusión en el segmento local: no
 * cruza un enlace enrutado, así que desde el hosting no hay forma de sondearlos
 * (comprobado contra el parque: cero respuestas a la sonda unicast).
 *
 * La vía que sí funciona es preguntarle al router. `/ip/neighbor` es la tabla
 * que él ya mantiene por su cuenta —descubre por MNDP, CDP y LLDP—, es la misma
 * que enseña WinBox, y es alcanzable por el túnel.
 *
 * Medido en el parque real: el barrido vio 25 equipos y la tabla de vecinos
 * 146, con **9 que solo veía el barrido y 130 que solo veía la tabla**. Ninguna
 * sustituye a la otra; hay que fundirlas.
 *
 * ## Por qué se filtra por el rango del barrido
 *
 * La tabla del router de borde alcanza toda la red del ISP: en el parque real
 * devolvió equipos de seis redes distintas, la mayoría CPE de abonado detrás de
 * cada torre. Volcarlos todos porque alguien pidió barrer la red de gestión
 * sería enterrar lo que pidió bajo cien filas que no buscaba. El rango que se
 * pidió barrer es el filtro.
 */
class NeighborScanEnricher
{
    /** Segundos de espera por router. Corto: son varios y bloquean la vuelta. */
    private const TIMEOUT = 8;

    public function __construct(private readonly DeviceDriverRegistry $registry)
    {
    }

    /**
     * @return int Hallazgos añadidos o enriquecidos.
     */
    public function enrich(NetworkScan $scan): int
    {
        $rango = CidrRange::tryParse($scan->cidr);

        if ($rango === null) {
            return 0;
        }

        $tocados = 0;

        foreach ($this->consultables() as $device) {
            foreach ($this->neighborsOf($device) as $neighbor) {
                if (!$neighbor->remoteIp || !$rango->contains($neighbor->remoteIp)) {
                    continue;
                }

                $tocados += $this->registrar($scan, $device, $neighbor);
            }
        }

        return $tocados;
    }

    /**
     * Equipos a los que el servidor puede preguntar por su cuenta.
     *
     * Los que sondea un agente quedan fuera: sus credenciales viajan al agente
     * y el servidor no tiene por qué alcanzarlos. Es el mismo criterio que ya
     * aplica `DiscoverTopologyLinksJob`.
     *
     * @return iterable<NetworkDevice>
     */
    private function consultables(): iterable
    {
        return NetworkDevice::query()
            ->active()
            ->whereNull('agent_id')
            ->get()
            ->filter(function (NetworkDevice $device) {
                $driver = $this->registry->for($device);

                return $driver !== null && $driver->supports(DeviceCapability::NEIGHBORS);
            });
    }

    /** @return list<NeighborLink> */
    private function neighborsOf(NetworkDevice $device): array
    {
        try {
            return $this->registry->for($device)?->neighbors($device, self::TIMEOUT) ?? [];
        } catch (Throwable $e) {
            // Un router que no responde no puede tumbar el enriquecido del
            // resto: el barrido ya tiene resultados que merecen guardarse.
            Log::warning('NeighborScanEnricher: fallo consultando vecinos.', [
                'device_id' => $device->id,
                'error'     => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function registrar(NetworkScan $scan, NetworkDevice $via, NeighborLink $neighbor): int
    {
        $existente = NetworkScanFinding::query()
            ->where('scan_id', $scan->id)
            ->where('ip_address', $neighbor->remoteIp)
            ->first();

        if ($existente !== null) {
            return $this->enriquecer($existente, $via, $neighbor);
        }

        NetworkScanFinding::create([
            'scan_id'     => $scan->id,
            'source'      => NetworkScanFinding::SOURCE_NEIGHBOR,
            'ip_address'  => $neighbor->remoteIp,
            'mac_address' => $neighbor->remoteMac,
            'vendor'      => DeviceVendor::fromMacAddress((string) $neighbor->remoteMac)?->value,
            // La tabla de vecinos llama «platform» al modelo comercial
            // («NanoStation loco M5») y «identity» al nombre que le puso el
            // instalador, que es justo el orden inverso al del protocolo de
            // Ubiquiti. Se colocan donde el resto del sistema los espera.
            'model'       => $neighbor->platform,
            'hostname'    => $neighbor->remoteIdentity,
            'discovered_via_device_id' => $via->id,
            'remote_interface'         => $neighbor->localInterface,
            'matched_device_id'        => $this->yaInventariado($neighbor),
        ]);

        return 1;
    }

    /**
     * El equipo ya lo había visto el barrido UDP: se completa lo que falte.
     *
     * No se sobrescribe nada que ya tuviera valor. El barrido pregunta al
     * propio equipo y la tabla de vecinos pregunta al router que lo ve; ante la
     * duda vale más lo que el equipo dice de sí mismo.
     */
    private function enriquecer(NetworkScanFinding $finding, NetworkDevice $via, NeighborLink $neighbor): int
    {
        $cambios = array_filter([
            'mac_address' => $finding->mac_address ?: $neighbor->remoteMac,
            'model'       => $finding->model       ?: $neighbor->platform,
            'hostname'    => $finding->hostname    ?: $neighbor->remoteIdentity,
            'discovered_via_device_id' => $finding->discovered_via_device_id ?: $via->id,
            'remote_interface'         => $finding->remote_interface ?: $neighbor->localInterface,
        ], fn ($valor) => $valor !== null);

        // Que lo vean las dos fuentes es información: significa que el equipo
        // responde y además está enlazado, no solo una de las dos cosas.
        $cambios['source'] = NetworkScanFinding::SOURCE_BOTH;

        if ($finding->vendor === null && $neighbor->remoteMac) {
            $cambios['vendor'] = DeviceVendor::fromMacAddress($neighbor->remoteMac)?->value;
        }

        $finding->update($cambios);

        return 1;
    }

    /** Id del equipo del inventario que ya corresponde a esta MAC, si lo hay. */
    private function yaInventariado(NeighborLink $neighbor): ?int
    {
        if (!$neighbor->remoteMac) {
            return null;
        }

        return NetworkDevice::query()
            ->where('mac_address', strtoupper($neighbor->remoteMac))
            ->value('id');
    }
}
