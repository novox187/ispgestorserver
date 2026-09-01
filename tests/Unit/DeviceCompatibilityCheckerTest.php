<?php

use App\Services\Provisioning\DeviceCompatibilityChecker;
use App\Services\Provisioning\ProvisioningSettings;

uses(Tests\TestCase::class);

/**
 * La compatibilidad se decide ANTES de escribir nada en el equipo. Un fallo
 * aquí no es un rechazo molesto: es la diferencia entre decirle al operador
 * «este modelo no sirve» y dejarle un router a medio configurar.
 */

beforeEach(function () {
    $this->checker = new DeviceCompatibilityChecker(new ProvisioningSettings());
});

it('acepta la versión mínima con WireGuard nativo', function () {
    $verdict = $this->checker->check('7.1', 'hEX S', true);

    expect($verdict['compatible'])->toBeTrue()
        ->and($verdict['code'])->toBeNull()
        ->and($verdict['normalized_version'])->toBe('7.1');
});

it('acepta versiones posteriores', function (string $version) {
    expect($this->checker->check($version, 'hEX S', true)['compatible'])->toBeTrue();
})->with(['7.2', '7.15.3', '7.16', '7.20.1']);

it('rechaza la rama 6 de RouterOS', function (string $version) {
    $verdict = $this->checker->check($version, 'RB750Gr3', null);

    expect($verdict['compatible'])->toBeFalse()
        ->and($verdict['code'])->toBe(DeviceCompatibilityChecker::CODE_VERSION_UNSUPPORTED)
        // El mensaje debe decir qué versión hace falta, no solo que no vale.
        ->and($verdict['reason'])->toContain('7.1');
})->with(['6.49.10', '6.48.6', '6.45']);

it('compara por número y no por texto', function () {
    // '7.10' es posterior a '7.9', pero alfabéticamente es anterior: una
    // comparación de cadenas rechazaría equipos perfectamente válidos.
    expect($this->checker->check('7.10', null, true)['compatible'])->toBeTrue()
        ->and($this->checker->check('7.9', null, true)['compatible'])->toBeTrue();
});

it('normaliza las etiquetas de precompilación y de rama', function (string $raw, string $expected) {
    expect($this->checker->normalizeVersion($raw))->toBe($expected);
})->with([
    ['7.16beta2', '7.16'],
    ['7.12rc1', '7.12'],
    ['6.48.6 (long-term)', '6.48.6'],
    ['7.15.3 (stable)', '7.15.3'],
    ['7.1', '7.1'],
]);

it('rechaza cuando no puede determinar la versión', function (?string $version) {
    $verdict = $this->checker->check($version, 'hEX S', true);

    expect($verdict['compatible'])->toBeFalse()
        ->and($verdict['code'])->toBe(DeviceCompatibilityChecker::CODE_VERSION_UNKNOWN);
})->with([null, '', '   ', 'desconocida']);

it('rechaza un equipo cuya versión basta pero que no expone WireGuard', function () {
    // Los SMIPS de poca memoria (hAP lite, RB941) corren RouterOS 7 con un
    // juego de paquetes recortado. Preguntarle al equipo es más fiable que
    // mantener una lista de modelos.
    $verdict = $this->checker->check('7.15.3', 'hAP lite', false);

    expect($verdict['compatible'])->toBeFalse()
        ->and($verdict['code'])->toBe(DeviceCompatibilityChecker::CODE_WIREGUARD_UNAVAILABLE)
        ->and($verdict['reason'])->toContain('hAP lite');
});

it('acepta cuando la disponibilidad de WireGuard aún no se ha comprobado', function () {
    // En la detección inicial todavía no se ha entrado al equipo; no tener el
    // dato no puede ser motivo de rechazo.
    expect($this->checker->check('7.15.3', 'hEX S', null)['compatible'])->toBeTrue();
});

it('menciona el modelo en el motivo del rechazo', function () {
    $verdict = $this->checker->check('6.49', 'RB750Gr3', null);

    expect($verdict['reason'])->toContain('RB750Gr3');
});
