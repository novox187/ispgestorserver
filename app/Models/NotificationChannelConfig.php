<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Configuración persistida de un canal de notificaciones.
 *
 * El campo `credentials` se almacena cifrado vía Laravel Crypt; nunca se devuelve
 * en claro al frontend (el controlador filtra las propiedades sensibles).
 *
 * @property int    $id
 * @property string $channel_key
 * @property bool   $enabled
 * @property array|null  $credentials
 * @property array|null  $settings
 */
class NotificationChannelConfig extends Model
{
    public const CACHE_PREFIX = 'notifications:channel-config:';

    protected $fillable = [
        'channel_key',
        'enabled',
        'credentials',
        'settings',
    ];

    protected $casts = [
        'enabled'     => 'boolean',
        'settings'    => 'array',
        'credentials' => 'encrypted:array',
    ];

    protected static function booted(): void
    {
        static::saved(fn (self $cfg) => Cache::forget(self::CACHE_PREFIX . $cfg->channel_key));
        static::deleted(fn (self $cfg) => Cache::forget(self::CACHE_PREFIX . $cfg->channel_key));
    }

    public static function forChannel(string $channelKey): ?self
    {
        return Cache::remember(
            self::CACHE_PREFIX . $channelKey,
            now()->addMinutes(10),
            fn () => self::where('channel_key', $channelKey)->first()
        );
    }
}
