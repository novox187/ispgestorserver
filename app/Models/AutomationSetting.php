<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AutomationSetting extends Model
{
    use Auditable;

    protected $fillable = [
        'key',
        'name',
        'description',
        'job_class',
        'queue',
        'enabled',
        'schedule_type',
        'schedule_config',
        'params',
        'params_schema',
        'last_run_at',
    ];

    protected $casts = [
        'enabled'         => 'boolean',
        'schedule_config' => 'array',
        'params'          => 'array',
        'params_schema'   => 'array',
        'last_run_at'     => 'datetime',
    ];

    public const SCHEDULE_TYPES = [
        'every_five_minutes',
        'every_ten_minutes',
        'every_fifteen_minutes',
        'every_thirty_minutes',
        'hourly',
        'daily',
        'monthly',
        'cron',
    ];

    public static function getCached(string $key): ?self
    {
        return Cache::rememberForever(self::cacheKey($key), function () use ($key) {
            return self::where('key', $key)->first();
        });
    }

    public static function getParam(string $key, string $param, mixed $default = null): mixed
    {
        $setting = self::getCached($key);
        return $setting?->params[$param] ?? $default;
    }

    public static function flushCache(): void
    {
        $keys = self::pluck('key');
        foreach ($keys as $key) {
            Cache::forget(self::cacheKey($key));
        }
        Cache::forget('automation_settings_all');
    }

    protected static function booted(): void
    {
        static::saved(function (self $s) {
            Cache::forget(self::cacheKey($s->key));
            Cache::forget('automation_settings_all');
        });

        static::deleted(function (self $s) {
            Cache::forget(self::cacheKey($s->key));
            Cache::forget('automation_settings_all');
        });
    }

    private static function cacheKey(string $key): string
    {
        return "automation_setting:{$key}";
    }
}
