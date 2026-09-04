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
use App\Services\Devices\Dto\RadioTelemetry;
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

        /**
         * Coherente con `probe()` a propósito: en un driver real las dos leen lo
         * mismo del equipo, y el monitor se apoya en esa coherencia para sacar la
         * serie de métricas de la misma llamada con la que decide si está vivo.
         */
        public function telemetry(NetworkDevice $d, ?int $t = null): DeviceTelemetry
        {
            if (!$this->reachable) {
                return DeviceTelemetry::unreachable($this->error);
            }

            return new DeviceTelemetry(
                reachable:        true,
                uptimeSeconds:    123456,
                cpuLoadPercent:   17.5,
                memoryFreeBytes:  40_000_000,
                memoryTotalBytes: 65_011_712,
                model:            'NanoStation M5',
                firmware:         'XW.v6.3.11',
                radio:            new RadioTelemetry(
                    ssid:      'ENLACE-NORTE',
                    mode:      'sta',
                    signalDbm: -67,
                    security:  'WPA2-AES',
                ),
            );
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

// ── La lectura que se aprovechaba a medias ───────────────────────────────────

it('guarda la telemetría del sondeo, no solo si el equipo responde', function () {
    // El equipo acababa de contar su CPU y su memoria en la misma llamada con la
    // que se comprueba que está vivo, y eso se tiraba: el panel enseñaba de él
    // dos guiones donde de las antenas con agente enseña barras.
    $device = makeUbiquitiAntenna();
    registerFakeDriver(reachable: true);

    (new MonitorDeviceConnectivityJob())->handle(app(DeviceDriverRegistry::class));

    $sample = \App\Models\DeviceMetricSample::where('device_id', $device->id)->first();

    expect($sample)->not->toBeNull()
        ->and($sample->cpu_load_percent)->toBe(17.5)
        ->and($sample->memory_total_bytes)->toBe(65011712)
        ->and($sample->signal_dbm)->toBe(-67);
});

it('deja el resumen de la ficha listo para el listado y el mapa', function () {
    $device = makeUbiquitiAntenna();
    registerFakeDriver(reachable: true);

    (new MonitorDeviceConnectivityJob())->handle(app(DeviceDriverRegistry::class));

    expect($device->refresh()->last_signal_dbm)->toBe(-67)
        ->and($device->last_ssid)->toBe('ENLACE-NORTE')
        ->and($device->last_security)->toBe('WPA2-AES')
        ->and($device->last_telemetry_at)->not->toBeNull();
});

it('no guarda ninguna muestra de un equipo que no responde', function () {
    // Una fila de ceros se leería como un equipo parado, que es justo lo
    // contrario de «no se sabe nada de él».
    $device = makeUbiquitiAntenna();
    registerFakeDriver(reachable: false);

    (new MonitorDeviceConnectivityJob())->handle(app(DeviceDriverRegistry::class));

    expect(\App\Models\DeviceMetricSample::where('device_id', $device->id)->count())->toBe(0);
});
