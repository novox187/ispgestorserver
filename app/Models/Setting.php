<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $table = 'system_settings';

    protected $fillable = [
        'module',
        'group',
        'key',
        'value',
        'data_type',
        'description',
        'is_public',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    public function scopeGroup($query, string $group)
    {
        return $query->where('group', $group);
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /**
     * Cast the raw string `value` to its declared data_type when reading.
     */
    public function getTypedValueAttribute(): mixed
    {
        return match ($this->data_type) {
            'integer' => (int) $this->value,
            'float'   => (float) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json'    => json_decode($this->value, true),
            default   => $this->value, // 'string' | 'text'
        };
    }

    // -------------------------------------------------------------------------
    // Cache helpers
    // -------------------------------------------------------------------------

    /**
     * Retrieve a setting by key, casting its value. Result is cached forever
     * and invalidated automatically when the record is saved/deleted.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = self::cacheKey($key);

        $setting = Cache::rememberForever($cacheKey, function () use ($key) {
            return self::where('key', $key)->first();
        });

        if ($setting === null) {
            return $default;
        }

        return $setting->typed_value;
    }

    /**
     * Write or create a setting and flush its cache entry.
     */
    public static function set(string $key, mixed $value, array $attributes = []): self
    {
        $setting = self::updateOrCreate(
            ['key' => $key],
            array_merge($attributes, ['value' => is_array($value) ? json_encode($value) : (string) $value])
        );

        Cache::forget(self::cacheKey($key));
        Cache::forget('system_settings_all');

        return $setting;
    }

    /**
     * Return all settings as key => typed_value, using a persistent cache.
     */
    public static function allCached(): array
    {
        return Cache::rememberForever('system_settings_all', function () {
            return self::all()->mapWithKeys(
                fn (self $s) => [$s->key => $s->typed_value]
            )->all();
        });
    }

    /**
     * Flush the full settings cache (call after bulk updates).
     */
    public static function flushCache(): void
    {
        $keys = self::pluck('key');
        foreach ($keys as $key) {
            Cache::forget(self::cacheKey($key));
        }
        Cache::forget('system_settings_all');
    }

    // -------------------------------------------------------------------------
    // Model events — keep cache consistent automatically
    // -------------------------------------------------------------------------

    protected static function booted(): void
    {
        static::saved(function (self $setting) {
            Cache::forget(self::cacheKey($setting->key));
            Cache::forget('system_settings_all');
        });

        static::deleted(function (self $setting) {
            Cache::forget(self::cacheKey($setting->key));
            Cache::forget('system_settings_all');
        });
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private static function cacheKey(string $key): string
    {
        return "system_setting:{$key}";
    }
}
