<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resumen por hora de las lecturas de un equipo: lo que sobrevive a la poda.
 *
 * Guarda mínimo, media y máximo y no solo la media, porque la media sola miente
 * justo en el caso que interesa: un enlace que se cae treinta segundos cada hora
 * tiene una media excelente y es el que hay que ir a revisar.
 */
class DeviceMetricHourly extends Model
{
    protected $table = 'device_metric_hourly';

    protected $fillable = [
        'device_id',
        'bucket_hour',
        'sample_count',
        'signal_min_dbm',
        'signal_avg_dbm',
        'signal_max_dbm',
        'ccq_min_percent',
        'ccq_avg_percent',
        'snr_min_db',
        'snr_avg_db',
        'cpu_avg_percent',
        'cpu_max_percent',
    ];

    protected $casts = [
        'bucket_hour'     => 'datetime',
        'sample_count'    => 'integer',
        'signal_min_dbm'  => 'integer',
        'signal_avg_dbm'  => 'float',
        'signal_max_dbm'  => 'integer',
        'ccq_min_percent' => 'integer',
        'ccq_avg_percent' => 'float',
        'snr_min_db'      => 'integer',
        'snr_avg_db'      => 'float',
        'cpu_avg_percent' => 'float',
        'cpu_max_percent' => 'float',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'device_id');
    }
}
