<?php

use App\Enums\DeviceRole;
use App\Enums\DeviceVendor;
use App\Jobs\MonitorDeviceConnectivityJob;
use App\Models\NetworkDevice;
use App\Models\NotificationLog;
use App\Notifications\Core\Enums\NotificationCategory;
use App\Services\Devices\DeviceCapability;
use App\Services\Devices\DeviceDriver;
use App\Services\Devices\DeviceDriverRegistry;
use App\Services\Devices\Dto\DeviceTelemetry;
use App\Services\Devices\Dto\ProbeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * El monitor dejó de ser específico de MikroTik.
 *
 * Estos tests usan un driver falso —no un mock de Mockery— a propósito: implementa
 * la interfaz de verdad, así que si mañana cambia, el error salta al compilar en
 * vez de pasar inadvertido dentro de un `shouldReceive()`.
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

function registerFakeDriver(bool $reachable, string $error = 'sin respuesta'): void
{
    $driver = new class($reachable, $error) implements DeviceDriver {
        public function __construct(private bool $reachable, private string $error) {}
        public function vendor(): string { return DeviceVendor::UBIQUITI->value; }
        public function name(): string { return 'airos'; }
        public function supports(DeviceCapability $c): bool { return true; }

        public function probe(NetworkDevice $d, ?int $t = null): ProbeResult
        {
            return $this->reachable
                ? ProbeResult::up(model: 'NanoStation M5', firmware: 'XW.v6.3.11')
                : ProbeResult::down($this->error);
        }

        public function telemetry(NetworkDevice $d, ?int $t = null): DeviceTelemetry
        {
            return DeviceTelemetry::unreachable($this->error);
        }

        public function neighbors(NetworkDevice $d, ?int $t = null): array { return []; }

        public function normalize(array $raw): DeviceTelemetry
        {
            return DeviceTelemetry::unparsed('driver de prueba');
        }
    };

    app(DeviceDriverRegistry::class)->register($driver);
}

/**
 * Los campos de salud se escriben con `forceFill` y no por asignación masiva:
 * `NetworkDevice` los deja fuera de `$fillable` a propósito, porque los escribe
 * el monitor y nunca la entrada de un formulario o de la API.
 */
function makeUbiquitiAntenna(array $overrides = [], array $health = []): NetworkDevice
{
    $device = NetworkDevice::create(array_merge([
        'name'      => 'Enlace Torre Norte',
        'vendor'    => DeviceVendor::UBIQUITI,
        'role'      => DeviceRole::BACKHAUL_AP,
        'driver'    => 'airos',
        'host'      => '10.9.0.5',
        'username'  => 'ubnt',
        'password'  => 'ubnt',
        'is_active' => true,
    ], $overrides));

    if ($health !== []) {
        $device->forceFill($health)->save();
    }

    return $device;
}

it('alerta de una antena Ubiquiti caída, sin saber que es Ubiquiti', function () {
    $antenna = makeUbiquitiAntenna(health: ['consecutive_failures' => 1]);
    registerFakeDriver(reachable: false, error: 'connection refused');

    (new MonitorDeviceConnectivityJob())->handle(app(DeviceDriverRegistry::class));

    $log = NotificationLog::first();

    expect($log)->not->toBeNull()
        ->and($log->recipient)->toBe('chat-critical')
        ->and($antenna->refresh()->connectivity_status)->toBe('disconnected');
});

it('nombra al fabricante correcto en la alerta', function () {
    makeUbiquitiAntenna(health: ['consecutive_failures' => 1]);
    registerFakeDriver(reachable: false);

    (new MonitorDeviceConnectivityJob())->handle(app(DeviceDriverRegistry::class));

    // Antes el título decía «MikroTik» para cualquier equipo. En una red mixta
    // eso manda al técnico a mirar el armario equivocado.
    expect(NotificationLog::first()->title)->toContain('Ubiquiti');
});

it('refresca modelo y firmware con lo que devuelve el sondeo', function () {
    $antenna = makeUbiquitiAntenna();
    registerFakeDriver(reachable: true);

    (new MonitorDeviceConnectivityJob())->handle(app(DeviceDriverRegistry::class));

    // El sondeo ya tuvo que hablar con el equipo: aprovecharlo para corregir el
    // inventario sale gratis y evita que se quede obsoleto tras un cambio de
    // firmware que nadie apuntó.
    expect($antenna->refresh()->model)->toBe('NanoStation M5')
        ->and($antenna->firmware_version)->toBe('XW.v6.3.11')
        ->and($antenna->connectivity_status)->toBe('connected');
});

it('se salta los equipos cuyo driver no está implementado y sigue con el resto', function () {
    // `edgeos` no tiene driver registrado: la fila queda huérfana.
    $huerfano = makeUbiquitiAntenna(['name' => 'Sin driver', 'driver' => 'edgeos'], ['consecutive_failures' => 5]);

    (new MonitorDeviceConnectivityJob())->handle(app(DeviceDriverRegistry::class));

    // Ni alerta ni excepción: se ignora y el ciclo termina.
    expect(NotificationLog::count())->toBe(0)
        ->and($huerfano->refresh()->consecutive_failures)->toBe(5);
});

it('omite los equipos inactivos', function () {
    makeUbiquitiAntenna(['is_active' => false], ['consecutive_failures' => 5]);
    registerFakeDriver(reachable: false);

    (new MonitorDeviceConnectivityJob())->handle(app(DeviceDriverRegistry::class));

    expect(NotificationLog::count())->toBe(0);
});
