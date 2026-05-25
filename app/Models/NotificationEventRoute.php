<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Ruteo de una categoría hacia un canal específico, editable desde el panel.
 *
 * Cuando existe al menos una fila habilitada para una categoría, el
 * NotificationRouter ignora la configuración severity_routes y usa estas filas.
 *
 * @property int    $id
 * @property string $category
 * @property string $channel_key
 * @property bool   $enabled
 * @property ?string $address_override
 * @property array|null  $extra
 */
class NotificationEventRoute extends Model
{
    public const CACHE_KEY = 'notifications:event-routes:all';

    protected $fillable = [
        'category',
        'channel_key',
        'enabled',
        'address_override',
        'extra',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'extra'   => 'array',
    ];

    protected static function booted(): void
    {
        $bust = fn () => Cache::forget(self::CACHE_KEY);
        static::saved($bust);
        static::deleted($bust);
    }

    /**
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function cached(): \Illuminate\Support\Collection
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(10),
            fn () => self::query()->get()
        );
    }
}
