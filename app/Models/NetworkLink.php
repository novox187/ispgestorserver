<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una conexión entre dos equipos.
 *
 * ## El orden de los extremos está normalizado
 *
 * Un enlace entre A y B es el mismo que entre B y A. Se guarda siempre con el id
 * menor en `a_device_id`, porque el autodescubrimiento llega por los dos
 * extremos: el AP airOS reporta a sus estaciones y cada MikroTik reporta a sus
 * vecinos. Sin normalizar habría dos filas para el mismo enlace y el mapa
 * dibujaría dos líneas superpuestas que el operador tendría que limpiar.
 *
 * ## Un enlace que deja de verse no se borra
 *
 * Se conserva con `last_seen_at` viejo. Que el descubrimiento no lo vea puede
 * significar que se cayó —que es justo lo que hay que mirar— y borrarlo haría
 * desaparecer del mapa la evidencia del problema.
 */
class NetworkLink extends Model
{
    use Auditable;

    public const STATUS_DISCOVERED = 'discovered';
    public const STATUS_CONFIRMED  = 'confirmed';
    public const STATUS_ARCHIVED   = 'archived';

    public const SOURCE_MANUAL        = 'manual';
    public const SOURCE_NEIGHBOR      = 'neighbor';
    public const SOURCE_AIROS_STATION = 'airos_station';

    protected $fillable = [
        'a_device_id',
        'b_device_id',
        'a_interface',
        'b_interface',
        'type',
        'status',
        'discovery_source',
        'last_seen_at',
        'expected_capacity_mbps',
        'notes',
    ];

    protected $casts = [
        'last_seen_at'           => 'datetime',
        'expected_capacity_mbps' => 'integer',
    ];

    public function endpointA(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'a_device_id');
    }

    public function endpointB(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'b_device_id');
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_DISCOVERED, self::STATUS_CONFIRMED]);
    }

    /**
     * Registra un enlace entre dos equipos, sin duplicar si ya existe.
     *
     * Normaliza el orden de los extremos y refresca `last_seen_at`. Si el enlace
     * ya estaba confirmado, **no se lo devuelve a `discovered`**: que el
     * descubrimiento lo vuelva a ver no puede deshacer la decisión que tomó un
     * operador.
     */
    public static function record(
        int $deviceIdA,
        int $deviceIdB,
        string $source,
        array $attributes = [],
    ): ?self {
        // Un equipo no se enlaza consigo mismo: eso es un dato mal leído.
        if ($deviceIdA === $deviceIdB) {
            return null;
        }

        [$a, $b] = $deviceIdA < $deviceIdB
            ? [$deviceIdA, $deviceIdB]
            : [$deviceIdB, $deviceIdA];

        // Si los extremos vinieron al revés, sus interfaces también.
        if ($deviceIdA > $deviceIdB && isset($attributes['a_interface'], $attributes['b_interface'])) {
            [$attributes['a_interface'], $attributes['b_interface']] =
                [$attributes['b_interface'], $attributes['a_interface']];
        }

        $link = static::firstOrNew(['a_device_id' => $a, 'b_device_id' => $b]);

        $link->fill(array_merge($attributes, [
            'discovery_source' => $link->exists ? $link->discovery_source : $source,
            'last_seen_at'     => now(),
        ]));

        if (!$link->exists) {
            $link->status = self::STATUS_DISCOVERED;
        }

        $link->save();

        return $link;
    }
}
