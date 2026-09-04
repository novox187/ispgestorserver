<?php

use App\Models\MikrotikRouter;
use App\Services\Devices\DeviceCapability;
use App\Services\Devices\Drivers\RouterOsDriver;
use App\Services\MikrotikHealthChecker;

/**
 * El driver de RouterOS traduce `/system/resource/print` al vocabulario común.
 *
 * Se prueba contra un `MikrotikHealthChecker` doblado en vez de contra un
 * RouterOS real: lo que interesa aquí es la traducción, no el transporte.
 */
function fakeChecker(array|Throwable $response): MikrotikHealthChecker
{
    return new class($response) extends MikrotikHealthChecker {
        public function __construct(private array|Throwable $response)
        {
            parent::__construct();
        }

        public function resources(\App\Models\NetworkDevice $router, ?int $timeoutOverride = null): array
        {
            if ($this->response instanceof Throwable) {
                throw $this->response;
            }

            return $this->response;
        }
    };
}

/**
 * Sin contraseña a propósito: el cast `encrypted` necesitaría el encriptador de
 * la aplicación, y estas pruebas son unitarias. El checker está doblado, así que
 * las credenciales no se usan para nada.
 */
function routerStub(): MikrotikRouter
{
    return new MikrotikRouter(['name' => 'R1', 'host' => '10.0.0.1', 'username' => 'a']);
}

it('declara el fabricante y el driver que casan con el inventario', function () {
    $driver = new RouterOsDriver(fakeChecker([]));

    expect($driver->vendor())->toBe(MikrotikRouter::VENDOR)
        ->and($driver->name())->toBe(MikrotikRouter::DRIVER)
        ->and($driver->supports(DeviceCapability::PROBE))->toBeTrue()
        ->and($driver->supports(DeviceCapability::TELEMETRY))->toBeTrue()
        /*
         * Y la radio, desde que el parque resultó tener SXT y LHG haciendo de
         * CPE inalámbrico: mientras esto dijo `false`, la ficha de uno de ellos
         * salía sin señal, sin SNR y sin CCQ aunque el equipo los publicara.
         * Ver `tests/Feature/Devices/RouterOsRadioTest.php`.
         */
        ->and($driver->supports(DeviceCapability::RADIO))->toBeTrue();
});

it('devuelve el equipo vivo con su identidad al sondearlo', function () {
    $driver = new RouterOsDriver(fakeChecker([
        'board-name' => 'RB750Gr3',
        'version'    => '7.11.2 (stable)',
        'uptime'     => '1w2d3h4m5s',
    ]));

    $result = $driver->probe(routerStub());

    expect($result->ok)->toBeTrue()
        ->and($result->model)->toBe('RB750Gr3')
        ->and($result->firmware)->toBe('7.11.2 (stable)')
        ->and($result->uptimeSeconds)->toBe(604800 + 172800 + 10800 + 240 + 5);
});

it('convierte un fallo de conexión en un resultado, nunca en una excepción', function () {
    $driver = new RouterOsDriver(fakeChecker(new RuntimeException('Connection timed out')));

    $result = $driver->probe(routerStub());

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe('Connection timed out');
});

it('lee uptimes que omiten unidades', function () {
    // RouterOS no rellena con ceros: «3h20s» es una respuesta legal.
    $driver = new RouterOsDriver(fakeChecker(['uptime' => '3h20s']));

    expect($driver->probe(routerStub())->uptimeSeconds)->toBe(10800 + 20);
});

it('normaliza la telemetría de recursos', function () {
    $driver = new RouterOsDriver(fakeChecker([
        'board-name'   => 'CCR1009',
        'version'      => '7.14',
        'uptime'       => '2h',
        'cpu-load'     => '17',
        'free-memory'  => '805306368',
        'total-memory' => '2147483648',
    ]));

    $t = $driver->telemetry(routerStub());

    expect($t->reachable)->toBeTrue()
        ->and($t->cpuLoadPercent)->toBe(17.0)
        ->and($t->memoryFreeBytes)->toBe(805306368)
        ->and($t->memoryTotalBytes)->toBe(2147483648)
        ->and($t->model)->toBe('CCR1009')
        // Un router de núcleo no tiene radio: eso no es un enlace degradado.
        ->and($t->hasRadioMetrics())->toBeFalse();
});

it('marca el equipo inalcanzable sin lanzar cuando la telemetría falla', function () {
    $driver = new RouterOsDriver(fakeChecker(new RuntimeException('host unreachable')));

    $t = $driver->telemetry(routerStub());

    expect($t->reachable)->toBeFalse()
        ->and($t->error)->toBe('host unreachable');
});

it('tolera una respuesta sin los campos esperados', function () {
    // Un RouterOS muy antiguo, o una respuesta recortada: debe degradar a nulos
    // en vez de romper el ciclo de monitoreo.
    $driver = new RouterOsDriver(fakeChecker(['algo-inesperado' => 'x']));

    $result = $driver->probe(routerStub());

    expect($result->ok)->toBeTrue()
        ->and($result->model)->toBeNull()
        ->and($result->uptimeSeconds)->toBeNull()
        ->and($result->inventoryUpdates())->toBe([]);
});
