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

it('resuelve el driver por la columna del inventario', function () {
    $registry = app(DeviceDriverRegistry::class);

    $router = NetworkDevice::create([
        'name' => 'R1', 'vendor' => DeviceVendor::MIKROTIK, 'role' => DeviceRole::CORE_ROUTER,
        'driver' => 'routeros', 'host' => '10.0.0.1', 'username' => 'a', 'password' => 'b',
    ]);

    expect($registry->for($router))->not->toBeNull()
        ->and($registry->for($router)->name())->toBe('routeros');
});

it('devuelve null en lugar de lanzar cuando el driver no existe', function () {
    // Una fila puede quedar apuntando a un fabricante a medio incorporar —o a
    // uno que se retiró—. El monitoreo tiene que poder saltársela y seguir con
    // el resto del parque, no abortar el ciclo entero.
    $registry = app(DeviceDriverRegistry::class);

    $equipo = NetworkDevice::create([
        'name' => 'EdgeRouter', 'vendor' => DeviceVendor::UBIQUITI, 'role' => DeviceRole::EDGE_ROUTER,
        'driver' => 'edgeos', 'host' => '10.9.0.1', 'username' => 'ubnt', 'password' => 'ubnt',
    ]);

    expect($registry->for($equipo))->toBeNull();
});

/**
 * Contrato que todo driver debe cumplir, comprobado sobre los que estén
 * registrados de verdad.
 *
 * Los drivers hablan con hardware ajeno por redes que se caen. Una excepción sin
 * capturar en cualquiera de ellos tumbaría el ciclo de monitoreo y dejaría sin
 * sondear a todos los equipos que vinieran detrás, así que la regla «no lanzar
 * nunca» es del contrato, no del buen gusto.
 */
it('ningún driver registrado lanza al sondear un equipo inalcanzable', function () {
    $registry = app(DeviceDriverRegistry::class);

    expect($registry->all())->not->toBeEmpty();

    foreach ($registry->all() as $name => $driver) {
        $device = NetworkDevice::create([
            'name'   => "Equipo {$name}",
            'vendor' => $driver->vendor(),
            'role'   => DeviceRole::CORE_ROUTER,
            'driver' => $name,
            // Dirección no enrutable: el sondeo tiene que fallar sí o sí.
            'host'     => '192.0.2.1',
            'port'     => 8728,
            'username' => 'x',
            'password' => 'y',
        ]);

        $result = $driver->probe($device, 1);

        expect($result)->toBeInstanceOf(ProbeResult::class)
            ->and($result->ok)->toBeFalse()
            ->and($result->error)->not->toBeEmpty();

        expect($driver->telemetry($device, 1))->toBeInstanceOf(DeviceTelemetry::class);
    }
})->group('slow');

/**
 * El punto de extensión es la columna `driver`, no `vendor`.
 *
 * `vendor` está casteado a enum a propósito: dar de alta un fabricante nuevo
 * exige añadir su caso, con sus OUI y su driver por defecto. `driver` en cambio
 * es texto libre, porque un mismo fabricante puede necesitar varios transportes
 * —airOS 5.x y airOS 8.x acabarán siendo dos— y elegir cuál usa un equipo
 * concreto debe ser editar una fila, no desplegar código.
 */
it('un driver nuevo se integra sin tocar el registro', function () {
    $fake = new class implements DeviceDriver {
        public function vendor(): string { return DeviceVendor::UBIQUITI->value; }
        public function name(): string { return 'airos-8x'; }
        public function supports(DeviceCapability $c): bool { return $c === DeviceCapability::PROBE; }
        public function probe(NetworkDevice $d, ?int $t = null): ProbeResult { return ProbeResult::up(model: 'PowerBeam M5'); }
        public function telemetry(NetworkDevice $d, ?int $t = null): DeviceTelemetry
        {
            return DeviceTelemetry::unparsed('sin telemetría');
        }

        public function neighbors(NetworkDevice $d, ?int $t = null): array { return []; }

        public function normalize(array $raw): DeviceTelemetry
        {
            return DeviceTelemetry::unparsed('sin parser');
        }
    };

    $registry = app(DeviceDriverRegistry::class);
    $registry->register($fake);

    $device = NetworkDevice::create([
        'name' => 'X', 'vendor' => DeviceVendor::UBIQUITI, 'role' => DeviceRole::SECTOR_AP,
        'driver' => 'airos-8x', 'host' => '10.1.1.1', 'username' => 'a', 'password' => 'b',
    ]);

    expect($registry->for($device)->name())->toBe('airos-8x')
        ->and($registry->for($device)->probe($device)->model)->toBe('PowerBeam M5');
});
