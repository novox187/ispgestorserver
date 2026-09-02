<?php

namespace App\Services\Devices\Dto;

/**
 * Métricas de radio de un enlace inalámbrico, normalizadas entre fabricantes.
 *
 * Todos los campos son opcionales porque ningún fabricante los publica todos y
 * las familias de firmware varían entre sí. Un `null` significa «este equipo no
 * lo informa», nunca «vale cero»: confundir ambas cosas convertiría un dato que
 * falta en una alerta de enlace caído.
 */
final readonly class RadioTelemetry
{
    public function __construct(
        public ?string $ssid = null,
        public ?string $mode = null,
        public ?int $frequencyMhz = null,
        public ?int $channelWidthMhz = null,
        public ?int $signalDbm = null,
        public ?int $noiseFloorDbm = null,
        public ?int $ccqPercent = null,
        public ?float $txRateMbps = null,
        public ?float $rxRateMbps = null,
        public ?int $txPowerDbm = null,
        public ?int $distanceM = null,
        public ?int $stationCount = null,
        public ?string $remoteMac = null,
        /**
         * MAC de todos los equipos al otro lado de este enlace: el AP si somos
         * estación, o las estaciones asociadas si somos AP.
         *
         * Es lo que permite dibujar la topología inalámbrica sin que nadie la
         * declare a mano, y cruzada desde los dos extremos.
         *
         * @var list<string>
         */
        public array $peerMacs = [],
    ) {
    }

    /**
     * Relación señal/ruido en dB, la métrica que de verdad dice si un enlace va
     * bien. Se calcula en vez de guardarse porque no todos los equipos la
     * publican, pero casi todos dan señal y ruido por separado.
     */
    public function snrDb(): ?int
    {
        if ($this->signalDbm === null || $this->noiseFloorDbm === null) {
            return null;
        }

        return $this->signalDbm - $this->noiseFloorDbm;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ssid'              => $this->ssid,
            'mode'              => $this->mode,
            'frequency_mhz'     => $this->frequencyMhz,
            'channel_width_mhz' => $this->channelWidthMhz,
            'signal_dbm'        => $this->signalDbm,
            'noise_floor_dbm'   => $this->noiseFloorDbm,
            'snr_db'            => $this->snrDb(),
            'ccq_percent'       => $this->ccqPercent,
            'tx_rate_mbps'      => $this->txRateMbps,
            'rx_rate_mbps'      => $this->rxRateMbps,
            'tx_power_dbm'      => $this->txPowerDbm,
            'distance_m'        => $this->distanceM,
            'station_count'     => $this->stationCount,
            'remote_mac'        => $this->remoteMac,
        ];
    }
}
