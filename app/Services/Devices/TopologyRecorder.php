<?php

namespace App\Services\Devices;

use App\Models\NetworkDevice;
use App\Models\NetworkLink;
use Illuminate\Support\Facades\Log;

/**
 * Único sitio donde una MAC descubierta se convierte en un enlace del mapa.
 *
 * Las dos vías de descubrimiento acaban aquí —la tabla de vecinos de RouterOS y
 * la de estaciones asociadas de airOS— porque el trabajo delicado es el mismo en
 * ambas: normalizar la MAC, cruzarla con el inventario y no duplicar el enlace.
 * Tenerlo en dos sitios habría garantizado que se separaran.
 *
 * **Solo se registran enlaces entre equipos que YA están en el inventario.** Un
 * router ve por LLDP el switch de la oficina y el portátil del técnico; crear
 * dispositivos a partir de eso llenaría el inventario de cosas que nadie quiere
 * gestionar. Lo que aún no está se descubre con un barrido, que es donde el
 * operador decide.
 */
class TopologyRecorder
{
    /**
     * Registra los enlaces de un equipo con los pares cuyas MAC se indican.
     *
     * @param list<string> $peerMacs
     * @return int Enlaces registrados o refrescados.
     */
    public function recordPeers(
        NetworkDevice $device,
        array $peerMacs,
        string $source,
        string $type = 'wireless_ptp',
    ): int {
        $normalizadas = array_values(array_filter(array_map(
            fn ($mac) => $this->normalizeMac((string) $mac),
            $peerMacs,
        )));

        if ($normalizadas === []) {
            return 0;
        }

        $pares = NetworkDevice::query()
            ->whereIn('mac_address', $normalizadas)
            ->get(['id', 'mac_address']);

        $registrados = 0;

        foreach ($pares as $par) {
            if ($par->id === $device->id) {
                continue;
            }

            try {
                $link = NetworkLink::record($device->id, $par->id, $source, ['type' => $type]);

                if ($link !== null) {
                    $registrados++;
                }
            } catch (\Throwable $e) {
                // Un enlace que no se puede registrar no debe abortar el resto
                // del descubrimiento ni, peor, la ingesta de una muestra.
                Log::warning('TopologyRecorder: no se pudo registrar un enlace.', [
                    'device_id' => $device->id,
                    'peer_id'   => $par->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return $registrados;
    }

    /**
     * Normaliza a AA:BB:CC:DD:EE:FF.
     *
     * Cada fuente entrega la MAC a su manera —RouterOS en mayúsculas con dos
     * puntos, airOS a veces en minúsculas— y sin unificarlas el cruce con el
     * inventario fallaría en silencio: el enlace simplemente no aparecería, sin
     * error que lo delate.
     */
    private function normalizeMac(?string $mac): ?string
    {
        if ($mac === null) {
            return null;
        }

        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac) ?? '');

        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : null;
    }
}
