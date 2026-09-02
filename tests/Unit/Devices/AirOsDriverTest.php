<?php

use App\Enums\DeviceVendor;
use App\Services\Devices\DeviceCapability;
use App\Services\Devices\Drivers\AirOsDriver;

/**
 * El parser de airOS, contra respuestas reales de las dos familias del parque.
 *
 * Se prueba `normalize()` y no el transporte porque es ahí donde está la
 * dificultad: airOS 5.x/6.x (XW) y 8.x (XC) publican el mismo JSON con
 * diferencias de detalle, y equivocarse en una de ellas no rompe nada de forma
 * visible — solo puebla las gráficas de datos falsos.
 */
function airosFixture(string $name): array
{
    return json_decode(file_get_contents(__DIR__ . "/../../Fixtures/airos/{$name}.json"), true);
}

it('declara fabricante y capacidades coherentes con el inventario', function () {
    $driver = new AirOsDriver();

    expect($driver->vendor())->toBe(DeviceVendor::UBIQUITI->value)
        ->and($driver->name())->toBe('airos')
        ->and($driver->supports(DeviceCapability::RADIO))->toBeTrue()
        ->and($driver->supports(DeviceCapability::STATIONS))->toBeTrue();
});

it('lee una estación de airOS 6.x', function () {
    $t = (new AirOsDriver())->normalize(airosFixture('xw-6.3.11-station'));

    expect($t->reachable)->toBeTrue()
        ->and($t->model)->toBe('NanoStation M5')
        ->and($t->firmware)->toBe('XW.v6.3.11')
        ->and($t->uptimeSeconds)->toBe(1857600)
        ->and($t->radio->signalDbm)->toBe(-67)
        ->and($t->radio->noiseFloorDbm)->toBe(-95)
        ->and($t->radio->snrDb())->toBe(28)
        ->and($t->radio->ssid)->toBe('ENLACE-NORTE');
});

it('convierte el CCQ en tanto por mil a porcentaje', function () {
    // airOS 6.x publica 950 sobre 1000. Guardarlo tal cual daría un 950% y
    // reventaría la columna, que es un tinyint sin signo.
    $t = (new AirOsDriver())->normalize(airosFixture('xw-6.3.11-station'));

    expect($t->radio->ccqPercent)->toBe(95);
});

it('deja el CCQ intacto cuando ya viene en porcentaje', function () {
    // airOS 8.x lo publica sobre 100. Se distingue por el valor y no por la
    // versión: una lista de versiones envejecería mal.
    $t = (new AirOsDriver())->normalize(airosFixture('xc-8.7.4-ap'));

    expect($t->radio->ccqPercent)->toBe(88);
});

it('lee la frecuencia venga como texto o como número', function () {
    $driver = new AirOsDriver();

    expect($driver->normalize(airosFixture('xw-6.3.11-station'))->radio->frequencyMhz)->toBe(5805)
        ->and($driver->normalize(airosFixture('xc-8.7.4-ap'))->radio->frequencyMhz)->toBe(5500);
});

it('cuenta las estaciones asociadas por la lista, no por el contador', function () {
    // El fixture trae `count: 2` y tres estaciones: algunas versiones dejan el
    // contador desfasado, y la lista es el dato de verdad.
    $t = (new AirOsDriver())->normalize(airosFixture('xc-8.7.4-ap'));

    expect($t->radio->stationCount)->toBe(3);
});

it('anota la MAC del extremo remoto en modo estación', function () {
    // Es la mitad de un enlace punto a punto: con ella el mapa puede dibujarlo
    // sin que nadie lo declare a mano.
    $driver = new AirOsDriver();

    expect($driver->normalize(airosFixture('xw-6.3.11-station'))->radio->remoteMac)
        ->toBe('24:A4:3C:11:22:33')
        // En modo AP no hay «el otro extremo»: hay varios.
        ->and($driver->normalize(airosFixture('xc-8.7.4-ap'))->radio->remoteMac)->toBeNull();
});

it('un firmware que no sabe leer NO se marca como caído', function () {
    // Es la regla que decide si el monitoreo sirve: si el cliente actualiza una
    // tanda de antenas y el parser no las entiende, no pueden aparecer treinta
    // alertas de enlace caído sobre enlaces que funcionan.
    $t = (new AirOsDriver())->normalize(airosFixture('firmware-desconocido'));

    expect($t->reachable)->toBeTrue()
        ->and($t->error)->not->toBeNull()
        ->and($t->hasRadioMetrics())->toBeFalse();
});

it('tolera un equipo sin bloque wireless', function () {
    // Una antena en modo puente sin radio activa, o un firmware recortado.
    $t = (new AirOsDriver())->normalize([
        'host' => ['devmodel' => 'LiteBeam M5', 'fwversion' => 'XW.v6.1.7', 'uptime' => 100],
    ]);

    expect($t->reachable)->toBeTrue()
        ->and($t->model)->toBe('LiteBeam M5')
        ->and($t->radio)->toBeNull();
});
