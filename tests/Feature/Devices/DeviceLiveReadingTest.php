<?php

use App\Enums\DeviceRole;
use App\Enums\DeviceVendor;
use App\Models\DeviceMetricSample;
use App\Models\NetworkDevice;
use App\Services\Devices\DeviceCapability;
use App\Services\Devices\DeviceDriver;
use App\Services\Devices\DeviceDriverRegistry;
use App\Services\Devices\Dto\DeviceTelemetry;
use App\Services\Devices\Dto\ProbeResult;
use App\Services\Devices\Dto\RadioTelemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * La lectura en directo de la ficha.
 *
 * El ciclo de fondo sondea cada pocos minutos —cadencia pensada para vigilar
 * cientos de equipos— y mirando UNO eso se ve como una pantalla congelada.
 * Este canal es el que hace que la ficha se parezca a la interfaz del propio
 * equipo.
 *
 * Lo que de verdad se prueba aquí: que sondear cada pocos segundos NO infle la
 * tabla de muestras, y que un equipo inalcanzable no se lea como una avería.
 */
beforeEach(function () {
    $this->admin = makeSuperAdminEmployee();
});

function registrarDriverEnVivo(bool $alcanzable): void
{
    $driver = new class($alcanzable) implements DeviceDriver {
        public function __construct(private bool $alcanzable) {}
        public function vendor(): string { return DeviceVendor::UBIQUITI->value; }
        public function name(): string { return 'airos'; }
        public function supports(DeviceCapability $c): bool { return true; }

        public function probe(NetworkDevice $d, ?int $t = null): ProbeResult
        {
            return $this->alcanzable ? ProbeResult::up() : ProbeResult::down('sin ruta al host');
        }

        public function telemetry(NetworkDevice $d, ?int $t = null): DeviceTelemetry
        {
            if (!$this->alcanzable) {
                return DeviceTelemetry::unreachable('AIROS_UNREACHABLE: sin ruta al host');
            }

            return new DeviceTelemetry(
                reachable:       true,
                cpuLoadPercent:  33.5,
                uptimeSeconds:   4242,
                radio: new RadioTelemetry(ssid: 'AP_BELLEZA', signalDbm: -61, ccqPercent: 91),
            );
        }

        public function neighbors(NetworkDevice $d, ?int $t = null): array { return []; }
        public function normalize(array $raw): DeviceTelemetry { return DeviceTelemetry::unparsed('n/a'); }
    };

    app(DeviceDriverRegistry::class)->register($driver);
}

function antenaEnVivo(): NetworkDevice
{
    return NetworkDevice::create([
        'name'   => 'CPE Luis Álvarez',
        'vendor' => DeviceVendor::UBIQUITI,
        'role'   => DeviceRole::CPE,
        'driver' => 'airos',
        'host'   => '10.10.10.13',
    ]);
}

it('devuelve lo que el equipo contesta ahora mismo', function () {
    $device = antenaEnVivo();
    registrarDriverEnVivo(alcanzable: true);

    $res = $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/admin/network/devices/{$device->id}/live")
        ->assertOk();

    expect($res->json('data.ok'))->toBeTrue()
        ->and($res->json('data.telemetry.cpu_load_percent'))->toBe(33.5)
        ->and($res->json('data.telemetry.signal_dbm'))->toBe(-61)
        // Y la ficha vuelve con el resumen ya actualizado, para que la tarjeta
        // de detrás no se quede con el dato viejo.
        ->and($res->json('data.device.ssid'))->toBe('AP_BELLEZA');
});

it('sondear cada pocos segundos no infla la serie', function () {
    // Sin truncar al minuto serían doce filas por minuto en la tabla grande del
    // sistema, y además el resumen horario quedaría sesgado a favor de los
    // equipos que alguien estuvo mirando.
    $device = antenaEnVivo();
    registrarDriverEnVivo(alcanzable: true);

    foreach (range(1, 5) as $ignored) {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/network/devices/{$device->id}/live")
            ->assertOk();
    }

    expect(DeviceMetricSample::where('device_id', $device->id)->count())->toBe(1);
});

it('un equipo inalcanzable no es un error del panel', function () {
    // Que el servidor no llegue a una antena de la LAN del cliente es lo NORMAL:
    // la sondea un agente. Devolverlo como error haría que la ficha se pintara
    // rota sobre un enlace que funciona.
    $device = antenaEnVivo();
    registrarDriverEnVivo(alcanzable: false);

    $res = $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/admin/network/devices/{$device->id}/live")
        ->assertOk();

    expect($res->json('data.ok'))->toBeFalse()
        ->and($res->json('data.error'))->toContain('sin ruta al host')
        ->and(DeviceMetricSample::count())->toBe(0);
});

it('una lectura fallida no marca el equipo como caído', function () {
    // Decidir que algo está caído es del monitor periódico, que tiene un umbral
    // de fallos seguidos. Este canal registra hechos, no juicios.
    $device = antenaEnVivo();
    $device->forceFill(['connectivity_status' => NetworkDevice::STATUS_CONNECTED])->save();
    registrarDriverEnVivo(alcanzable: false);

    $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/admin/network/devices/{$device->id}/live")->assertOk();

    expect($device->refresh()->connectivity_status)->toBe(NetworkDevice::STATUS_CONNECTED)
        ->and($device->consecutive_failures)->toBe(0);
});
