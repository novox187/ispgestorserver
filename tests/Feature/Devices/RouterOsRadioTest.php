<?php

use App\Services\Devices\DeviceCapability;
use App\Services\Devices\Drivers\RouterOsDriver;
use App\Services\MikrotikHealthChecker;

/**
 * La radio de los MikroTik inalámbricos.
 *
 * El módulo daba por hecho que todos los MikroTik del parque eran routers, y el
 * cliente tiene SXT y LHG haciendo de CPE: en la ficha de uno de ellos la señal,
 * el SNR y el CCQ salían vacíos aunque el equipo los estuviera publicando,
 * porque el driver declaraba no tener radio y nadie se los pedía.
 *
 * Se prueba sobre `normalize()` y no contra un equipo real porque es donde vive
 * la traducción; el transporte ya está cubierto por los tests del checker.
 */
function routerOsDriver(): RouterOsDriver
{
    return new RouterOsDriver(app(MikrotikHealthChecker::class));
}

/** Respuesta de un SXT Lite5 asociado a un sector, como la da RouterOS 6.49. */
function sxtEstacion(array $overrides = []): array
{
    return array_merge([
        'resource' => [
            'uptime'       => '9d15h22m10s',
            'version'      => '6.49.8 (stable)',
            'board-name'   => 'SXT Lite5',
            'cpu-load'     => '28',
            'free-memory'  => '42000000',
            'total-memory' => '65011712',
        ],
        'wireless' => [[
            'disabled'         => 'false',
            'name'             => 'wlan1',
            'ssid'             => 'SECTOR-NORTE',
            'mode'             => 'station-bridge',
            'band'             => '5ghz-a/n',
            'frequency'        => '5180',
            'tx-power'         => '17',
            'security-profile' => 'perfil-clientes',
        ]],
        'registrations' => [[
            'mac-address'     => '48:8f:5a:11:22:33',
            'signal-strength' => '-67dBm@6Mbps',
            'signal-to-noise' => '32',
            'tx-ccq'          => '89',
            'rx-ccq'          => '74',
            'tx-rate'         => '130.0Mbps-2S',
            'rx-rate'         => '117Mbps',
            'distance'        => '1200',
        ]],
    ], $overrides);
}

it('ahora declara que sabe leer una radio', function () {
    // Mientras dijo que no, la ficha de un CPE MikroTik salía con la mitad de
    // los indicadores en blanco.
    expect(routerOsDriver()->supports(DeviceCapability::RADIO))->toBeTrue()
        ->and(routerOsDriver()->supports(DeviceCapability::STATIONS))->toBeTrue();
});

it('lee la señal, el SNR y el CCQ de un CPE asociado', function () {
    $t = routerOsDriver()->normalize(sxtEstacion());

    expect($t->reachable)->toBeTrue()
        ->and($t->cpuLoadPercent)->toBe(28.0)
        ->and($t->model)->toBe('SXT Lite5')
        ->and($t->radio)->not->toBeNull()
        // «-67dBm@6Mbps»: interesa el entero, no la modulación.
        ->and($t->radio->signalDbm)->toBe(-67)
        // RouterOS da el SNR hecho y NO publica el ruido de fondo: se toma tal
        // cual en vez de inventar un ruido que haga cuadrar la resta.
        ->and($t->radio->snrDb())->toBe(32)
        ->and($t->radio->noiseFloorDbm)->toBeNull()
        // Manda el CCQ de transmisión, que es lo que este equipo consigue
        // entregar y lo que enseña también airOS.
        ->and($t->radio->ccqPercent)->toBe(89)
        ->and($t->radio->txRateMbps)->toBe(130.0)
        ->and($t->radio->rxRateMbps)->toBe(117.0)
        ->and($t->radio->distanceM)->toBe(1200);
});

it('describe cómo está configurado el enlace', function () {
    $t = routerOsDriver()->normalize(sxtEstacion());

    expect($t->radio->ssid)->toBe('SECTOR-NORTE')
        ->and($t->radio->mode)->toBe('station-bridge')
        ->and($t->radio->frequencyMhz)->toBe(5180)
        ->and($t->radio->txPowerDbm)->toBe(17)
        ->and($t->radio->security)->toBe('perfil-clientes')
        // En modo estación la única fila de la tabla es el AP: es el otro
        // extremo del enlace, no una estación asociada.
        ->and($t->radio->remoteMac)->toBe('48:8F:5A:11:22:33')
        ->and($t->radio->stationCount)->toBeNull();
});

it('en modo AP cuenta las estaciones y no inventa un extremo remoto', function () {
    // Una media de las señales de veinte clientes no describe a ninguno; lo que
    // significa algo es cuántos hay.
    $raw = sxtEstacion();
    $raw['wireless'][0]['mode'] = 'ap-bridge';
    $raw['registrations'][] = ['mac-address' => 'AA:BB:CC:DD:EE:FF', 'signal-strength' => '-71'];

    $t = routerOsDriver()->normalize($raw);

    expect($t->radio->stationCount)->toBe(2)
        ->and($t->radio->remoteMac)->toBeNull()
        ->and($t->radio->peerMacs)->toContain('AA:BB:CC:DD:EE:FF');
});

it('un router de núcleo sigue sin radio, que no es lo mismo que caído', function () {
    // La forma antigua —el array plano de /system/resource— tiene que seguir
    // funcionando: es lo que sigue enviando cualquier llamada previa.
    $t = routerOsDriver()->normalize([
        'uptime' => '3h20s', 'version' => '7.14', 'board-name' => 'CCR1009', 'cpu-load' => '4',
    ]);

    expect($t->reachable)->toBeTrue()
        ->and($t->radio)->toBeNull()
        ->and($t->cpuLoadPercent)->toBe(4.0);
});

it('no devuelve una radio vacía cuando el equipo no la tiene', function () {
    // Un objeto con todo a null haría creer al panel que hay radio y que no
    // dice nada, cuando lo que pasa es que no la hay.
    $raw = sxtEstacion();
    $raw['wireless'] = [];
    $raw['registrations'] = [];

    expect(routerOsDriver()->normalize($raw)->radio)->toBeNull();
});

it('ignora la interfaz inalámbrica deshabilitada', function () {
    $raw = sxtEstacion();
    array_unshift($raw['wireless'], ['disabled' => 'true', 'name' => 'wlan2', 'ssid' => 'APAGADA']);

    expect(routerOsDriver()->normalize($raw)->radio->ssid)->toBe('SECTOR-NORTE');
});

it('lee el ancho de canal solo cuando la banda lo declara', function () {
    // Suponer 20 MHz cuando no se sabe se leería como una limitación real del
    // enlace.
    $conAncho = sxtEstacion();
    $conAncho['wireless'][0]['band'] = '5ghz-onlyac/40-mhz';

    expect(routerOsDriver()->normalize($conAncho)->radio->channelWidthMhz)->toBe(40)
        ->and(routerOsDriver()->normalize(sxtEstacion())->radio->channelWidthMhz)->toBeNull();
});
