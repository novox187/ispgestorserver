<?php

use App\Enums\DeviceRole;
use App\Enums\DeviceVendor;
use App\Models\NetworkDevice;
use App\Services\Devices\Drivers\AirOsDriver;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * El protocolo de acceso de airOS.
 *
 * Dos cosas, las dos comprobadas contra una NanoStation loco M5 con airOS
 * 6.3.6, que entre las dos dejaron el parque entero sin poder sondearse:
 *
 * 1. **La cookie de sesión no tiene un nombre fijo.** Se llama `AIROS_` más la
 *    MAC del equipo sin separadores. Buscar `AIROS_SESSIONID`, que es lo que
 *    hacía esto, no encuentra nunca nada.
 * 2. **`login.cgi` no emite la sesión: valida la que el cliente ya trae.** El
 *    navegador la tiene porque al abrir la antena hizo un GET antes de ver el
 *    formulario. Y el POST responde **302 igual, haya funcionado o no**, así
 *    que sin la semilla parece que todo fue bien y es `status.cgi` quien lo
 *    desmiente con otro 302.
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
            // El nombre real lleva dentro la MAC del equipo. Si el driver
            // buscara uno fijo, no encontraría esta.
            return Http::response($formulario, 200, $abreSesion
                ? ['Set-Cookie' => 'AIROS_FCECDA2C91C1=8df9ead2ea819391b4ba53b1879c8432; Path=/; Version=1']
                : []);
        }

        if ($ruta === '/login.cgi') {
            // El firmware responde 302 tanto al login bueno como al malo; la
            // diferencia solo se ve al pedir `status.cgi`.
            return Http::response('', 302, ['Location' => '/index.cgi']);
        }

        // Sin sesión válida no hay 401: desvía de vuelta al formulario.
        return $aceptaCredenciales
            ? Http::response(['host' => ['devmodel' => 'NanoStation loco M5', 'fwversion' => 'XW.v6.3.6', 'uptime' => 144173]], 200)
            : Http::response('', 302, ['Location' => '/login.cgi']);
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

it('no da por hecho un nombre de cookie: el real lleva la MAC dentro', function () {
    // Con `AIROS_SESSIONID` —el nombre que se suponía— el driver abortaba antes
    // de intentar siquiera el acceso, con unas credenciales correctas.
    airOsFalso();

    expect(app(AirOsDriver::class)->telemetry(antenaDePrueba())->reachable)->toBeTrue();
});

it('manda el formulario como multipart, igual que el equipo', function () {
    airOsFalso();

    app(AirOsDriver::class)->telemetry(antenaDePrueba());

    $login = collect(Http::recorded())->first(fn ($par) => $par[0]->method() === 'POST')[0];

    expect($login->header('Content-Type')[0])->toContain('multipart/form-data')
        ->and($login->body())->toContain('name="username"')
        ->and($login->body())->toContain('ubnt')
        ->and($login->body())->toContain('secreto')
        // Campo oculto que lleva el formulario del propio equipo.
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

// ── El TLS del firmware viejo ────────────────────────────────────────────────

it('baja el nivel de OpenSSL para que el firmware viejo pueda saludar', function () {
    // Una LiteBeam M5 con airOS 6.2.0 negocia Diffie-Hellman con 1024 bits y
    // OpenSSL 3 la corta antes de hablar: «dh key too small». El nivel 1 es el
    // mínimo que lo admite; comprobado contra el parque real que el 0 no aporta.
    //
    // La antena de mentira habla por HTTP simulado y nunca ejercitaría esto, así
    // que lo que se vigila es el cableado: que la opción llegue a cURL. Es justo
    // lo que se pierde en silencio al reordenar el método, y solo se notaría en
    // la torre, sobre el firmware que nadie tiene delante.
    $peticion = (function () {
        $metodo = new ReflectionMethod(AirOsDriver::class, 'request');
        $metodo->setAccessible(true);

        return $metodo->invoke(app(AirOsDriver::class), new CookieJar(), 8);
    })();

    $opciones = new ReflectionProperty($peticion, 'options');
    $opciones->setAccessible(true);

    expect($opciones->getValue($peticion)['curl'][CURLOPT_SSL_CIPHER_LIST] ?? null)
        ->toBe('DEFAULT@SECLEVEL=1');
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
