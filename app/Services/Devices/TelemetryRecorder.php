<?php

namespace App\Services\Devices;

use App\Models\DeviceMetricSample;
use App\Models\NetworkDevice;
use App\Services\Devices\Dto\DeviceTelemetry;

/**
 * Anota una lectura de un equipo: un punto en su serie y el resumen de su ficha.
 *
 * Vive aparte porque hay **dos** sitios que leen telemetría y la tienen que
 * guardar igual: el ciclo periódico que vigila el parque y la pantalla de
 * diagnóstico cuando alguien la mira en directo. Cuando esto estaba metido en el
 * job, la pantalla no podía guardar nada y la lectura que el operador tenía
 * delante se perdía en cuanto cerraba la ficha.
 *
 * No decide nada sobre conectividad: eso es de `ConnectivityRecorder`, que
 * aplica el umbral de fallos. Aquí solo se registran hechos.
 */
class TelemetryRecorder
{
    /**
     * @return bool ¿Se guardó algo? Falso cuando la lectura no era aprovechable.
     */
    public function record(NetworkDevice $device, DeviceTelemetry $telemetry): bool
    {
        // Respondió pero no se le entendió: no hay nada que guardar. Una fila de
        // ceros se leería como un equipo parado, que es lo contrario de lo que
        // pasó.
        if (!$telemetry->reachable || $telemetry->error !== null) {
            return false;
        }

        $radio = $telemetry->radio;

        /*
         * Truncado al minuto contra el índice único (device_id, sampled_at). Es
         * lo que permite que la ficha en directo sondee cada pocos segundos sin
         * inflar la serie: doce lecturas por minuto colapsan en una fila, y de
         * paso el resumen horario no queda sesgado por los equipos que alguien
         * estuvo mirando.
         */
        DeviceMetricSample::query()->updateOrCreate(
            ['device_id' => $device->id, 'sampled_at' => now()->startOfMinute()],
            array_filter([
                'uptime_seconds'     => $telemetry->uptimeSeconds,
                'cpu_load_percent'   => $telemetry->cpuLoadPercent,
                'memory_free_bytes'  => $telemetry->memoryFreeBytes,
                'memory_total_bytes' => $telemetry->memoryTotalBytes,
                'signal_dbm'         => $radio?->signalDbm,
                'noise_floor_dbm'    => $radio?->noiseFloorDbm,
                'snr_db'             => $radio?->snrDb(),
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
            ], fn ($v) => $v !== null),
        );

        /*
         * Y el resumen desnormalizado de la ficha, que es de donde leen el
         * listado y el mapa. La identidad del enlace solo se pisa cuando la
         * lectura la trae: una respuesta sin bloque de radio no puede borrar el
         * SSID conocido justo cuando hay una avería que diagnosticar.
         */
        $device->forceFill(array_merge([
            'last_telemetry_at' => now(),
            'last_signal_dbm'   => $radio?->signalDbm,
            'last_ccq_percent'  => $radio?->ccqPercent,
        ], array_filter([
            'last_ssid'          => $radio?->ssid,
            'last_wireless_mode' => $radio?->mode,
            'last_security'      => $radio?->security,
            'last_remote_mac'    => $radio?->remoteMac,
        ], fn ($v) => $v !== null)))->save();

        return true;
    }
}
