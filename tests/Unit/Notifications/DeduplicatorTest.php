<?php

use App\Notifications\Core\Deduplicator;
use App\Notifications\Core\Enums\NotificationCategory;
use Illuminate\Support\Facades\Cache;

uses(Tests\TestCase::class);

beforeEach(function () {
    Cache::store('array')->clear();
    config(['notifications.deduplication.store' => 'array']);
    config(['notifications.deduplication.default_ttl_seconds' => 300]);
    config(['notifications.deduplication.per_category' => [
        NotificationCategory::MIKROTIK_CONNECTIVITY->value => 600,
    ]]);
});

it('permite la primera adquisición de una clave', function () {
    $dedup = new Deduplicator(Cache::store('array'));
    expect($dedup->tryAcquire('test:key', NotificationCategory::WORKER_SUMMARY))->toBeTrue();
});

it('rechaza adquisiciones repetidas de la misma clave dentro de la ventana', function () {
    $dedup = new Deduplicator(Cache::store('array'));
    $first = $dedup->tryAcquire('test:dup', NotificationCategory::WORKER_SUMMARY);
    $second = $dedup->tryAcquire('test:dup', NotificationCategory::WORKER_SUMMARY);

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse();
});

it('permite reusar la clave después de forget()', function () {
    $dedup = new Deduplicator(Cache::store('array'));
    $dedup->tryAcquire('test:forget', NotificationCategory::WORKER_SUMMARY);
    $dedup->forget('test:forget');

    expect($dedup->tryAcquire('test:forget', NotificationCategory::WORKER_SUMMARY))->toBeTrue();
});
