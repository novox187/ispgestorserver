<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un lugar físico donde hay equipos: una torre, una azotea, el POP.
 *
 * Existe separado de los equipos porque son cosas distintas: mover una antena de
 * torre no debería obligar a reescribir sus coordenadas, y el mapa necesita
 * agrupar media docena de equipos en un punto en vez de apilar marcadores en la
 * misma posición.
 */
class NetworkSite extends Model
{
    use Auditable;

    public const TYPES = ['tower', 'pole', 'rooftop', 'pop', 'office', 'customer'];

    protected $fillable = [
        'name',
        'type',
        'address',
        'latitude',
        'longitude',
        'elevation_m',
        'parent_site_id',
        'notes',
    ];

    protected $casts = [
        'latitude'    => 'decimal:7',
        'longitude'   => 'decimal:7',
        'elevation_m' => 'integer',
    ];

    public function devices(): HasMany
    {
        return $this->hasMany(NetworkDevice::class, 'site_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_site_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_site_id');
    }

    /** Sitios que se pueden pintar en el mapa. */
    public function scopeLocated(Builder $query): Builder
    {
        return $query->whereNotNull('latitude')->whereNotNull('longitude');
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'tower'    => 'Torre',
            'pole'     => 'Poste',
            'rooftop'  => 'Azotea',
            'pop'      => 'POP',
            'office'   => 'Oficina',
            'customer' => 'Domicilio de cliente',
            default    => (string) $this->type,
        };
    }
}
