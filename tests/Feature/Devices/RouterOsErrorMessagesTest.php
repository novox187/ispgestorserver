<?php

use App\Enums\DeviceRole;
use App\Enums\DeviceVendor;
use App\Models\NetworkDevice;
use App\Services\Devices\Drivers\RouterOsDriver;
use App\Services\MikrotikHealthChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RouterOS\Exceptions\BadCredentialsException;
use RouterOS\Exceptions\ConnectException;

uses(RefreshDatabase::class);

/**
 * Lo que el operador lee cuando un MikroTik no contesta.
 *
 * La biblioteca dice «Unable to establish socket session, Operation timed out»,
 * que es cierto y no sirve de nada: no distingue un equipo apagado de una IP que
 * ya es de otro, ni de un servicio API sin habilitar —que en un CPE de abonado
 * viene desactivado de fábrica y es la causa más frecuente—.
 *
 * Se traduce por el tipo de excepción y no por el texto, que cambia con la
 * versión de la biblioteca y con el idioma del sistema.
 */
function routerConChequeadorQueLanza(Throwable $fallo): RouterOsDriver
{
    $checker = new class($fallo) extends MikrotikHealthChecker {
        public function __construct(private Throwable $fallo) {}

        public function resources($device, ?int $timeoutSeconds = null): array
        {
            throw $this->fallo;
        }
    };

    return new RouterOsDriver($checker);
}

function routerDePrueba(): NetworkDevice
{
    return NetworkDevice::create([
        'name' => 'Betty Romero', 'vendor' => DeviceVendor::MIKROTIK,
        'role' => DeviceRole::CPE, 'driver' => 'routeros',
        'host' => '10.10.10.61', 'port' => 8728,
        'username' => 'admin', 'password' => 'x',
    ]);
}

it('dice dónde mirar cuando no hay respuesta', function () {
    $driver = routerConChequeadorQueLanza(
        new ConnectException('Unable to establish socket session, Operation timed out')
    );

    $error = $driver->probe(routerDePrueba())->error;

    expect($error)->toContain('10.10.10.61:8728')
        // Las dos causas que el operador puede comprobar sin salir de casa.
        ->and($error)->toContain('encendido')
        ->and($error)->toContain('API');
});

it('no confunde una contraseña mala con un equipo apagado', function () {
    $driver = routerConChequeadorQueLanza(new BadCredentialsException('Invalid user name or password'));

    expect($driver->probe(routerDePrueba())->error)
        ->toContain('usuario o la contraseña');
});

it('un fallo que no reconoce se cuenta tal cual', function () {
    // Traducir solo lo que se entiende: inventar una explicación para un fallo
    // desconocido manda a mirar donde no es.
    $driver = routerConChequeadorQueLanza(new RuntimeException('algo raro y nuevo'));

    expect($driver->probe(routerDePrueba())->error)->toBe('algo raro y nuevo');
});
