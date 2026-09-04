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
        /**
         * Calidad y capacidad airMAX, los dos indicadores propios de Ubiquiti.
         *
         * No sustituyen al CCQ ni se derivan de la señal: un enlace puede tener
         * -55 dBm y estar dando el 11 % de su capacidad porque el sector está
         * saturado, y ese caso no se ve en ninguna métrica de las otras.
         */
        public ?int $airmaxQualityPercent = null,
        public ?int $airmaxCapacityPercent = null,
        public ?float $txRateMbps = null,
        public ?float $rxRateMbps = null,
        /**
         * Tráfico instantáneo en kbps, tal como lo publica el equipo.
         *
         * Es caudal real cursado, no la tasa de negociación del enlace: la
         * pareja `txRateMbps`/`rxRateMbps` dice a cuánto *podría* ir, y esta a
         * cuánto está yendo.
         */
        public ?int $txThroughputKbps = null,
        public ?int $rxThroughputKbps = null,
        public ?int $txPowerDbm = null,
        public ?int $distanceM = null,
        public ?int $stationCount = null,
        /** Cifrado en uso, tal como lo nombra el equipo: «WPA2-AES», «none». */
        public ?string $security = null,
        public ?string $remoteMac = null,
        /**
         * SNR tal como lo publica el equipo, para los que lo dan hecho.
         *
         * RouterOS informa `signal-to-noise` pero no el ruido de fondo, así que
         * la resta de `snrDb()` no puede calcularlo. Guardar el valor dado y
         * dejar `noiseFloorDbm` nulo es más honesto que inventar un ruido a
         * partir del cual la resta cuadre.
         */
        public ?int $reportedSnrDb = null,
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
        // Manda el que informa el equipo: si lo publica, es su medida y no una
        // reconstrucción nuestra.
        if ($this->reportedSnrDb !== null) {
            return $this->reportedSnrDb;
        }

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
            'airmax_quality_percent'  => $this->airmaxQualityPercent,
            'airmax_capacity_percent' => $this->airmaxCapacityPercent,
            'tx_rate_mbps'      => $this->txRateMbps,
            'rx_rate_mbps'      => $this->rxRateMbps,
            'tx_throughput_kbps' => $this->txThroughputKbps,
            'rx_throughput_kbps' => $this->rxThroughputKbps,
            'tx_power_dbm'      => $this->txPowerDbm,
            'distance_m'        => $this->distanceM,
            'station_count'     => $this->stationCount,
            'security'          => $this->security,
            'remote_mac'        => $this->remoteMac,
        ];
    }
}
