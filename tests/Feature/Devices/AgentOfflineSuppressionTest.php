<?php

use App\Enums\DeviceRole;
use App\Enums\DeviceVendor;
use App\Jobs\MonitorDeviceConnectivityJob;
use App\Models\NetworkDevice;
use App\Models\NotificationLog;
use App\Notifications\Core\Enums\NotificationCategory;
use App\Services\Devices\DeviceDriverRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Qué pasa cuando se cae el agente que vigila el parque.
 *
 * Es el fallo operativo más probable de todo el módulo y el que decide si el
 * monitoreo sirve de algo. Un agente vigila cientos de antenas; si se cae, todas
 * dejan de reportar a la vez. Tratarlo como cientos de caídas genera cientos de
 * alertas que entierran cualquier incidencia real y enseñan al operador a
 * ignorar el canal — momento en el que el sistema deja de servir para nada.
 */
beforeEach(function () {
    config(['queue.default' => 'sync']);
    config(['notifications.queue.connection' => null]);
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

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
});

function antennaWatchedBy(int $agentId, array $health = []): NetworkDevice
{
    static $n = 0;
    $n++;

    $device = NetworkDevice::create([
        'name'      => "Antena {$n}",
        'vendor'    => DeviceVendor::UBIQUITI,
        'role'      => DeviceRole::BACKHAUL_AP,
        'driver'    => 'airos',
        'host'      => "10.9.0.{$n}",
        'username'  => 'ubnt',
        'password'  => 'ubnt',
        'agent_id'  => $agentId,
        'is_active' => true,
    ]);

    if ($health !== []) {
        $device->forceFill($health)->save();
    }

    return $device;
}

function runMonitor(): void
{
    (new MonitorDeviceConnectivityJob())->handle(app(DeviceDriverRegistry::class));
}

it('marca stale y NO alerta cuando el agente responsable está caído', function () {
    $agent = makeProvisioningAgent('monitor');
    $agent['agent']->forceFill(['last_seen_at' => now()->subHour()])->save();

    $antennas = collect(range(1, 3))->map(fn () => antennaWatchedBy($agent['agent']->id, [
        'consecutive_failures' => 9,
        'last_telemetry_at'    => now()->subHour(),
    ]));

    runMonitor();

    // Ni una sola alerta de dispositivo: la del agente, que emite
    // MonitorProvisioningAgentsJob, es la que describe el problema de verdad.
    expect(NotificationLog::count())->toBe(0);

    $antennas->each(
        fn (NetworkDevice $a) => expect($a->refresh()->connectivity_status)->toBe(NetworkDevice::STATUS_STALE)
    );
});

it('distingue «no lo sé» de «está caído»', function () {
    $agent = makeProvisioningAgent('monitor');
    $agent['agent']->forceFill(['last_seen_at' => now()->subHour()])->save();

    $antenna = antennaWatchedBy($agent['agent']->id, [
        'connectivity_status'  => NetworkDevice::STATUS_CONNECTED,
        'last_telemetry_at'    => now()->subHour(),
    ]);

    runMonitor();

    expect($antenna->refresh()->connectivity_status)
        ->toBe(NetworkDevice::STATUS_STALE)
        ->not->toBe(NetworkDevice::STATUS_DISCONNECTED);
});

it('sí alerta de un equipo caído cuando su agente está vivo', function () {
    $agent = makeProvisioningAgent('monitor');
    $agent['agent']->forceFill(['last_seen_at' => now()])->save();

    $antenna = antennaWatchedBy($agent['agent']->id, [
        'consecutive_failures' => 3,
        'last_telemetry_at'    => now()->subMinutes(2),
    ]);

    runMonitor();

    expect(NotificationLog::count())->toBe(1)
        ->and(NotificationLog::first()->recipient)->toBe('chat-critical')
        ->and($antenna->refresh()->connectivity_status)->toBe(NetworkDevice::STATUS_DISCONNECTED);
});

it('no alerta por debajo del umbral de fallos', function () {
    $agent = makeProvisioningAgent('monitor');
    $agent['agent']->forceFill(['last_seen_at' => now()])->save();

    antennaWatchedBy($agent['agent']->id, [
        'consecutive_failures' => 1,
        'last_telemetry_at'    => now()->subMinutes(2),
    ]);

    runMonitor();

    expect(NotificationLog::count())->toBe(0);
});

it('no juzga un equipo del que el agente aún no ha reportado nada', function () {
    // Recién asignado: no hay dato que interpretar, y suponer lo peor sería
    // alertar de una caída que nadie ha observado.
    $agent = makeProvisioningAgent('monitor');
    $agent['agent']->forceFill(['last_seen_at' => now()])->save();

    $antenna = antennaWatchedBy($agent['agent']->id, ['consecutive_failures' => 9]);

    runMonitor();

    expect(NotificationLog::count())->toBe(0)
        ->and($antenna->refresh()->connectivity_status)->not->toBe(NetworkDevice::STATUS_DISCONNECTED);
});

it('no sondea directamente los equipos que tienen agente asignado', function () {
    // El servidor no alcanza la LAN del cliente: intentarlo solo gastaría el
    // ciclo en timeouts. Host no enrutable como prueba: si el job lo sondeara,
    // el test tardaría segundos y marcaría fallo.
    $agent = makeProvisioningAgent('monitor');
    $agent['agent']->forceFill(['last_seen_at' => now()])->save();

    $antenna = antennaWatchedBy($agent['agent']->id, ['last_telemetry_at' => now()]);
    $antenna->forceFill(['host' => '192.0.2.1'])->save();

    $before = $antenna->refresh()->consecutive_failures;

    runMonitor();

    expect($antenna->refresh()->consecutive_failures)->toBe($before);
});
