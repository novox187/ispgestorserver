<?php

use App\Enums\DeviceRole;
use App\Enums\DeviceVendor;
use App\Models\NetworkDevice;
use App\Services\Devices\DeviceCapability;
use App\Services\Devices\DeviceDriver;
use App\Services\Devices\DeviceDriverRegistry;
use App\Services\Devices\Dto\DeviceTelemetry;
use App\Services\Devices\Dto\ProbeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Lo que el botón de «probar credenciales» deja escrito.
 *
 * El sondeo manual hablaba con el equipo, lo veía responder y tiraba esa
 * evidencia: solo el trabajo periódico escribía el estado. El operador
 * arreglaba una contraseña, veía el aviso verde de que la antena contestaba, y
 * la ficha seguía diciendo «Desconectado» hasta el siguiente ciclo.
 */
beforeEach(function () {
    config(['queue.default' => 'sync', 'notifications.queue.connection' => null]);
    $this->admin = makeSuperAdminEmployee();
});

function driverQue(bool $responde): void
{
    app(DeviceDriverRegistry::class)->register(new class($responde) implements DeviceDriver {
        public function __construct(private bool $responde) {}
        public function vendor(): string { return DeviceVendor::UBIQUITI->value; }
        public function name(): string { return 'airos'; }
        public function supports(DeviceCapability $c): bool { return true; }

        public function probe(NetworkDevice $d, ?int $t = null): ProbeResult
        {
            return $this->responde
                ? ProbeResult::up(model: 'NanoStation loco M5', firmware: 'XW.v6.3.6')
                : ProbeResult::down('usuario o contraseña incorrectos');
        }

        public function telemetry(NetworkDevice $d, ?int $t = null): DeviceTelemetry
        {
            return DeviceTelemetry::unreachable('driver de prueba');
        }

        public function neighbors(NetworkDevice $d, ?int $t = null): array { return []; }

        public function normalize(array $raw): DeviceTelemetry
        {
            return DeviceTelemetry::unparsed('driver de prueba');
        }
    });
}

function antenaCaida(array $salud = []): NetworkDevice
{
    $device = NetworkDevice::create([
        'name' => 'AP SOL NACIENTE', 'vendor' => DeviceVendor::UBIQUITI,
        'role' => DeviceRole::BACKHAUL_AP, 'driver' => 'airos',
        'host' => '10.10.10.236', 'port' => 443,
        'username' => 'ubnt', 'password' => 'ubnt', 'is_active' => true,
    ]);

    // Los campos de salud van con `forceFill`: `NetworkDevice` los deja fuera de
    // `$fillable` porque los escribe el monitor, nunca un formulario.
    $device->forceFill(array_merge([
        'connectivity_status'  => 'disconnected',
        'consecutive_failures' => 7,
        'last_disconnected_at' => now()->subHour(),
    ], $salud))->save();

    return $device;
}

function probar(NetworkDevice $d)
{
    return test()->actingAs(test()->admin, 'sanctum')
        ->postJson("/api/admin/network/devices/{$d->id}/test");
}

// ── Un sondeo bueno es prueba de que el equipo está vivo ─────────────────────

it('deja el equipo como conectado en cuanto responde', function () {
    driverQue(responde: true);
    $device = antenaCaida();

    probar($device)->assertOk()->assertJsonPath('data.ok', true);

    expect($device->fresh()->connectivity_status)->toBe('connected')
        ->and($device->fresh()->consecutive_failures)->toBe(0)
        ->and($device->fresh()->last_connected_at)->not->toBeNull();
});

it('devuelve el estado nuevo, para no tener que repreguntar', function () {
    driverQue(responde: true);

    probar(antenaCaida())->assertJsonPath('data.connectivity_status', 'connected');
});

it('aprovecha para corregir modelo y firmware', function () {
    // Los dice el propio equipo y cambian sin que nadie los teclee.
    driverQue(responde: true);
    $device = antenaCaida();

    probar($device);

    expect($device->fresh()->model)->toBe('NanoStation loco M5')
        ->and($device->fresh()->firmware_version)->toBe('XW.v6.3.6');
});

// ── Uno malo no es prueba de nada ────────────────────────────────────────────

it('un sondeo fallido no toca el estado ni acerca el equipo a una alerta', function () {
    // Este botón se pulsa sobre todo mientras se ajustan credenciales. Un
    // intento fallido a propósito no debe empujar al equipo hacia una alerta de
    // caída: decidir eso es del monitor periódico, que tiene un umbral.
    driverQue(responde: false);
    $device = antenaCaida(['connectivity_status' => 'connected', 'consecutive_failures' => 0]);

    probar($device)->assertOk()->assertJsonPath('data.ok', false);

    expect($device->fresh()->connectivity_status)->toBe('connected')
        ->and($device->fresh()->consecutive_failures)->toBe(0);
});
