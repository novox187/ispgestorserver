<?php

namespace App\Notifications\Core;

use App\Notifications\Core\Enums\NotificationCategory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Filtra notificaciones duplicadas dentro de una ventana de tiempo.
 *
 * Usa una clave atómica en cache (`Cache::add`) para garantizar que en condiciones
 * de concurrencia (varios workers) solo una notificación con la misma clave pase.
 */
class Deduplicator
{
    private CacheRepository $cache;

    public function __construct(?CacheRepository $cache = null)
    {
        $store = config('notifications.deduplication.store', config('cache.default'));
        $this->cache = $cache ?? Cache::store($store);
    }

    /**
     * Intenta reservar la clave; retorna true si la operación procede (no era duplicada).
     */
    public function tryAcquire(string $dedupeKey, NotificationCategory $category): bool
    {
        $ttl = $this->ttlFor($category);
        return $this->cache->add($this->prefixed($dedupeKey), 1, $ttl);
    }

    public function forget(string $dedupeKey): void
    {
        $this->cache->forget($this->prefixed($dedupeKey));
    }

    private function ttlFor(NotificationCategory $category): int
    {
        $perCategory = config('notifications.deduplication.per_category', []);
        $default     = (int) config('notifications.deduplication.default_ttl_seconds', 300);

        return (int) ($perCategory[$category->value] ?? $default);
    }

    private function prefixed(string $key): string
    {
        return 'notifications:dedupe:' . $key;
    }
}
