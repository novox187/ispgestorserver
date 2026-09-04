<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una lectura puntual del estado de un equipo.
 *
 * **Sin el trait `Auditable` a propósito.** Se insertan decenas de miles al día;
 * auditarlas multiplicaría por dos el volumen de escritura para registrar que
 * un worker hizo lo que hace cada cinco minutos, y enterraría el historial de
 * cambios que sí importa bajo millones de filas de ruido.
 *
 * Retención corta: la poda las borra pasadas unas semanas y lo que sobrevive son
 * los agregados de `DeviceMetricHourly`.
 */
class DeviceMetricSample extends Model
{
    /**
     * Sin `created_at`/`updated_at`: `sampled_at` ya dice cuándo se leyó, y una
     * tabla de este tamaño no puede permitirse dos columnas de fecha que nadie
     * consulta.
     */
    public $timestamps = false;

    protected $fillable = [
        'device_id',
        'sampled_at',
        'uptime_seconds',
        'cpu_load_percent',
        'memory_free_bytes',
        'memory_total_bytes',
        'signal_dbm',
        'noise_floor_dbm',
        'snr_db',
        'ccq_percent',
        'airmax_quality_percent',
        'airmax_capacity_percent',
        'tx_rate_mbps',
        'rx_rate_mbps',
        'tx_throughput_kbps',
        'rx_throughput_kbps',
        'tx_power_dbm',
        'frequency_mhz',
        'channel_width_mhz',
        'distance_m',
        'station_count',
        'unparsed_payload',
    ];

    protected $casts = [
        'sampled_at'        => 'datetime',
        'uptime_seconds'    => 'integer',
        'cpu_load_percent'  => 'float',
        'memory_free_bytes' => 'integer',
        'memory_total_bytes' => 'integer',
        'signal_dbm'        => 'integer',
        'noise_floor_dbm'   => 'integer',
        'snr_db'            => 'integer',
        'ccq_percent'       => 'integer',
        'airmax_quality_percent'  => 'integer',
        'airmax_capacity_percent' => 'integer',
        'tx_rate_mbps'      => 'float',
        'rx_rate_mbps'      => 'float',
        'tx_throughput_kbps' => 'integer',
        'rx_throughput_kbps' => 'integer',
        'tx_power_dbm'      => 'integer',
        'frequency_mhz'     => 'integer',
        'channel_width_mhz' => 'integer',
        'distance_m'        => 'integer',
        'station_count'     => 'integer',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'device_id');
    }
}
