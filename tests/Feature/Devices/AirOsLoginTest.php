<?php

use App\Enums\DeviceRole;
use App\Enums\DeviceVendor;
use App\Models\NetworkDevice;
use App\Services\Devices\Drivers\AirOsDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * El protocolo de acceso de airOS.
 *
 * `login.cgi` **no emite una sesión al autenticar: valida la que el cliente ya
 * trae**. El navegador la tiene porque al abrir la antena hizo un GET antes de
 * ver el formulario; un cliente que va directo al POST no lleva ninguna, así
 * que no hay nada que validar y el equipo responde sin cookie.
 *
 * Eso, más que el formulario venga en `multipart/form-data` y no urlencoded,
 * dejó el parque entero sin poder sondearse: todas las antenas daban «rechazó
 * las credenciales» con credenciales buenas, verificadas a mano en la web del
 * propio equipo.
 */
function antenaDePrueba(array $overrides = []): NetworkDevice
{
    return NetworkDevice::create(array_merge([
        'name' => 'AP SOL NACIENTE', 'vendor' => DeviceVendor::UBIQUITI,
        'role' => DeviceRole::BACKHAUL_AP, 'driver' => 'airos',
        'host' => '10.10.10.236', 'port' => 443,
        'username' => 'ubnt', 'password' => 'secreto',
    ], $overrides));
}

/** Una antena de mentira que se comporta como el firmware real. */
function airOsFalso(bool $aceptaCredenciales = true, bool $abreSesion = true): void
{
    $formulario = '<html><form action="/login.cgi" enctype="multipart/form-data"></form></html>';

    Http::fake(function ($request) use ($aceptaCredenciales, $abreSesion, $formulario) {
        $ruta = parse_url($request->url(), PHP_URL_PATH);

        if ($ruta === '/login.cgi' && $request->method() === 'GET') {
            return Http::response($formulario, 200, $abreSesion
                ? ['Set-Cookie' => 'AIROS_SESSIONID=semilla123; path=/']
                : []);
        }

        if ($ruta === '/login.cgi') {
            // El firmware responde 302 tanto al login bueno como al malo; la
            // diferencia solo se ve al pedir `status.cgi`.
            return Http::response('', 302, ['Location' => '/status.cgi']);
        }

        // Sin sesión válida no hay 401: devuelve el formulario con un 200.
        return $aceptaCredenciales
            ? Http::response(['host' => ['devmodel' => 'NanoStation loco M5', 'fwversion' => 'XW.v6.3.6', 'uptime' => 144173]], 200)
            : Http::response($formulario, 200);
    });
}

// ── El orden de las peticiones ───────────────────────────────────────────────

it('siembra la sesión antes de autenticar', function () {
    airOsFalso();

    app(AirOsDriver::class)->telemetry(antenaDePrueba());

    $peticiones = collect(Http::recorded())->map(fn ($par) => [
        $par[0]->method(), parse_url($par[0]->url(), PHP_URL_PATH),
    ]);

    expect($peticiones->all())->toBe([
        ['GET',  '/login.cgi'],   // sin esto no hay sesión que validar
        ['POST', '/login.cgi'],
        ['GET',  '/status.cgi'],
    ]);
});

it('manda el formulario como multipart, que es lo que el CGI parsea', function () {
    airOsFalso();

    app(AirOsDriver::class)->telemetry(antenaDePrueba());

    $login = collect(Http::recorded())->first(fn ($par) => $par[0]->method() === 'POST')[0];

    expect($login->header('Content-Type')[0])->toContain('multipart/form-data')
        ->and($login->body())->toContain('name="username"')
        ->and($login->body())->toContain('ubnt')
        ->and($login->body())->toContain('secreto')
        // Campo oculto del formulario del equipo: hay firmwares que rechazan
        // el POST si falta.
        ->and($login->body())->toContain('name="uri"');
});

// ── Qué se lee del resultado ─────────────────────────────────────────────────

it('lee la telemetría cuando el acceso funciona', function () {
    airOsFalso();

    $telemetria = app(AirOsDriver::class)->telemetry(antenaDePrueba());

    expect($telemetria->reachable)->toBeTrue()
        ->and($telemetria->model)->toBe('NanoStation loco M5')
        ->and($telemetria->uptimeSeconds)->toBe(144173);
});

it('distingue una contraseña mala de un equipo que no habla airOS', function () {
    // Los dos casos acababan en el mismo mensaje —«rechazó las credenciales o
    // no devolvió sesión»— y se arreglan de formas distintas: uno es teclear
    // otra contraseña y el otro es que ahí no hay una antena.
    airOsFalso(aceptaCredenciales: false);

    expect(app(AirOsDriver::class)->telemetry(antenaDePrueba())->error)
        ->toContain('usuario o contraseña incorrectos');
});

it('avisa cuando el equipo ni siquiera abre sesión', function () {
    airOsFalso(abreSesion: false);

    expect(app(AirOsDriver::class)->telemetry(antenaDePrueba())->error)
        ->toContain('no abrió sesión');
});

// ── El sondeo, que es lo que ve el operador ──────────────────────────────────

it('el botón de probar credenciales da por vivo el equipo', function () {
    airOsFalso();

    $admin = makeSuperAdminEmployee();
    $device = antenaDePrueba();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/admin/network/devices/{$device->id}/test")
        ->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.model', 'NanoStation loco M5');
});
