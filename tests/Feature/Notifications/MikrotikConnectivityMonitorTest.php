<?php

use App\Jobs\MonitorMikrotikConnectivityJob;
use App\Models\MikrotikRouter;
use App\Models\NotificationLog;
use App\Notifications\Core\Enums\NotificationCategory;
use App\Notifications\Core\Enums\NotificationStatus;
use App\Services\MikrotikHealthChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['queue.default' => 'sync']);
    config(['notifications.queue.connection' => null]);
    config(['notifications.mikrotik_monitor.enabled' => true]);
    config(['notifications.mikrotik_monitor.consecutive_failures' => 2]);
    config(['notifications.deduplication.store' => 'array']);
    config(['cache.default' => 'array']);

    seedTelegramChannel(
        botToken: 'fake-token',
        defaultAddress: 'chat-default',
        routes: [
            NotificationCategory::MIKROTIK_CONNECTIVITY->value => 'chat-critical',
            NotificationCategory::MIKROTIK_RECOVERY->value     => 'chat-info',
        ]
    );

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
    ]);
});

function makeRouter(array $overrides = []): MikrotikRouter
{
    return MikrotikRouter::create(array_merge([
        'name'                 => 'Router Test',
        'host'                 => '10.0.0.1',
        'port'                 => 8728,
        'username'             => 'admin',
        'password'             => 'secret',
        'is_active'            => true,
        'connectivity_status'  => 'unknown',
        'consecutive_failures' => 0,
    ], $overrides));
}

it('no alerta tras un único fallo (por debajo del umbral)', function () {
    $router = makeRouter();

    $this->mock(MikrotikHealthChecker::class, function (MockInterface $m) {
        $m->shouldReceive('check')->andReturn(['ok' => false, 'error' => 'timeout']);
    });

    (new MonitorMikrotikConnectivityJob())->handle(app(MikrotikHealthChecker::class));

    expect(NotificationLog::count())->toBe(0);
    expect($router->refresh()->consecutive_failures)->toBe(1);
});

it('emite alerta CRITICAL tras alcanzar el umbral de fallos', function () {
    $router = makeRouter(['consecutive_failures' => 1]);

    $this->mock(MikrotikHealthChecker::class, function (MockInterface $m) {
        $m->shouldReceive('check')->andReturn(['ok' => false, 'error' => 'conn refused']);
    });

    (new MonitorMikrotikConnectivityJob())->handle(app(MikrotikHealthChecker::class));

    $log = NotificationLog::first();
    expect($log)->not->toBeNull()
        ->and($log->category)->toBe(NotificationCategory::MIKROTIK_CONNECTIVITY->value)
        ->and($log->recipient)->toBe('chat-critical');

    expect($router->refresh()->connectivity_status)->toBe('disconnected')
        ->and($router->last_disconnected_at)->not->toBeNull();
});

it('deduplica la alerta cuando se reejecuta el monitor inmediatamente', function () {
    makeRouter(['consecutive_failures' => 1]);

    $this->mock(MikrotikHealthChecker::class, function (MockInterface $m) {
        $m->shouldReceive('check')->andReturn(['ok' => false, 'error' => 'conn refused']);
    });

    (new MonitorMikrotikConnectivityJob())->handle(app(MikrotikHealthChecker::class));
    (new MonitorMikrotikConnectivityJob())->handle(app(MikrotikHealthChecker::class));

    $statuses = NotificationLog::pluck('status')->toArray();
    expect($statuses)->toContain(NotificationStatus::SENT->value)
        ->and($statuses)->toContain(NotificationStatus::DUPLICATED->value);
});

it('emite alerta INFO cuando un router disconnected vuelve a responder', function () {
    $router = makeRouter([
        'connectivity_status'  => 'disconnected',
        'consecutive_failures' => 3,
        'last_disconnected_at' => now()->subMinutes(15),
    ]);

    $this->mock(MikrotikHealthChecker::class, function (MockInterface $m) {
        $m->shouldReceive('check')->andReturn(['ok' => true, 'error' => null]);
    });

    (new MonitorMikrotikConnectivityJob())->handle(app(MikrotikHealthChecker::class));

    $log = NotificationLog::first();
    expect($log)->not->toBeNull()
        ->and($log->category)->toBe(NotificationCategory::MIKROTIK_RECOVERY->value)
        ->and($log->recipient)->toBe('chat-info');

    expect($router->refresh()->connectivity_status)->toBe('connected')
        ->and($router->consecutive_failures)->toBe(0);
});

it('omite routers inactivos', function () {
    makeRouter(['is_active' => false]);

    $this->mock(MikrotikHealthChecker::class, function (MockInterface $m) {
        $m->shouldNotReceive('check');
    });

    (new MonitorMikrotikConnectivityJob())->handle(app(MikrotikHealthChecker::class));

    expect(NotificationLog::count())->toBe(0);
});
