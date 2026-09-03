<?php

use App\Support\CidrRange;

/**
 * Filtro de rangos del descubrimiento.
 *
 * Decide qué vecinos de un router entran en un barrido y cuáles no. Si acepta
 * de más, un barrido de la red de gestión se llena con los cien y pico CPE de
 * abonado de otras torres; si acepta de menos, se pierden equipos.
 */
it('acepta lo que cae dentro y rechaza lo de fuera', function () {
    $rango = CidrRange::tryParse('10.10.10.0/24');

    expect($rango->contains('10.10.10.1'))->toBeTrue()
        ->and($rango->contains('10.10.10.255'))->toBeTrue()
        ->and($rango->contains('10.10.11.1'))->toBeFalse()
        ->and($rango->contains('192.168.14.70'))->toBeFalse();
});

it('respeta prefijos que no caen en frontera de octeto', function () {
    // El error clásico es comparar los tres primeros octetos como texto, que
    // funciona con /24 y falla con todo lo demás.
    $rango = CidrRange::tryParse('10.0.0.0/12');

    expect($rango->contains('10.15.255.254'))->toBeTrue()
        ->and($rango->contains('10.16.0.1'))->toBeFalse();
});

it('acepta un host suelto como /32', function () {
    $rango = CidrRange::tryParse('10.10.10.250/32');

    expect($rango->contains('10.10.10.250'))->toBeTrue()
        ->and($rango->contains('10.10.10.251'))->toBeFalse();
});

it('un /0 no desborda el desplazamiento', function () {
    // Desplazar 32 bits es comportamiento indefinido y en PHP devuelve el
    // operando intacto, así que este caso se resuelve aparte en el código.
    $rango = CidrRange::tryParse('0.0.0.0/0');

    expect($rango->contains('8.8.8.8'))->toBeTrue()
        ->and($rango->contains('10.10.10.1'))->toBeTrue();
});

it('normaliza un rango escrito desde una dirección de host', function () {
    // «10.10.10.57/24» es lo que teclea alguien copiando la IP de un equipo.
    $rango = CidrRange::tryParse('10.10.10.57/24');

    expect($rango->contains('10.10.10.1'))->toBeTrue();
});

it('devuelve null ante basura en vez de lanzar', function () {
    // El CIDR llega de fuera: de un formulario o de la fila de un barrido.
    expect(CidrRange::tryParse('no-es-un-cidr'))->toBeNull()
        ->and(CidrRange::tryParse('10.10.10.0'))->toBeNull()
        ->and(CidrRange::tryParse('10.10.10.0/33'))->toBeNull()
        ->and(CidrRange::tryParse('10.10.10.0/abc'))->toBeNull()
        ->and(CidrRange::tryParse('999.1.1.1/24'))->toBeNull()
        ->and(CidrRange::tryParse(''))->toBeNull();
});

it('una IP ilegible nunca cae dentro', function () {
    $rango = CidrRange::tryParse('10.10.10.0/24');

    expect($rango->contains('no-es-una-ip'))->toBeFalse()
        ->and($rango->contains(''))->toBeFalse();
});
