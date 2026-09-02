<?php

use App\Enums\DeviceRole;
use App\Enums\DeviceVendor;
use App\Models\Audit;
use App\Models\MikrotikRouter;
use App\Models\NetworkDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Blinda el invariante del router primary.
 *
 * De ese único registro salen las credenciales con las que se conecta medio
 * sistema. Quedarse sin primary hace que `EnsurePrimaryRouter` responda 423 y
 * que el módulo entero —colas, firewall, sincronización, monitoreo— deje de
 * funcionar de golpe. No había ni un test cubriéndolo, y la lógica acaba de
 * mudarse de los hooks del modelo a `PrimaryRouterObserver` para que también
 * valga cuando se opera por `NetworkDevice`.
 *
 * Los casos que entran por `NetworkDevice` son el motivo de existir del
 * observer: Eloquent despacha los eventos bajo el nombre de la clase concreta,
 * así que con la lógica en `MikrotikRouter::booted()` pasaban de largo sin
 * disparar nada.
 */
function makeMikrotik(array $overrides = []): MikrotikRouter
{
    static $n = 0;
    $n++;

    return MikrotikRouter::create(array_merge([
        'name'      => "Router {$n}",
        'host'      => "10.0.0.{$n}",
        'port'      => 8728,
        'username'  => 'admin',
        'password'  => 'secret',
        'is_active' => true,
    ], $overrides));
}

function makeAntenna(array $overrides = []): NetworkDevice
{
    static $n = 0;
    $n++;

    return NetworkDevice::create(array_merge([
        'name'      => "Antena {$n}",
        'vendor'    => DeviceVendor::UBIQUITI,
        'role'      => DeviceRole::BACKHAUL_AP,
        'driver'    => 'airos',
        'host'      => "10.9.0.{$n}",
        'username'  => 'ubnt',
        'password'  => 'ubnt',
        'is_active' => true,
    ], $overrides));
}

it('marca como primary y activo al primer router creado', function () {
    $router = makeMikrotik(['is_active' => null]);

    expect($router->is_primary)->toBeTrue()
        ->and($router->is_active)->toBeTrue();
});

it('no promueve a primary a los routers siguientes', function () {
    $first  = makeMikrotik();
    $second = makeMikrotik();

    expect($first->fresh()->is_primary)->toBeTrue()
        ->and($second->is_primary)->toBeFalse();
});

it('desmarca al anterior cuando se promueve otro', function () {
    $first  = makeMikrotik();
    $second = makeMikrotik();

    $second->is_primary = true;
    $second->save();

    expect($first->fresh()->is_primary)->toBeFalse()
        ->and($second->fresh()->is_primary)->toBeTrue()
        ->and(MikrotikRouter::where('is_primary', true)->count())->toBe(1);
});

it('deja constancia en auditoría de la despromoción', function () {
    $first  = makeMikrotik();
    $second = makeMikrotik();

    $second->is_primary = true;
    $second->save();

    $audit = Audit::forRecord('network_devices', $first->id)
        ->where('operation', 'PRIMARY_DEMOTED')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->new_values['promoted_router_id'])->toBe($second->id);
});

it('promueve un sustituto al borrar el primary', function () {
    $first  = makeMikrotik();
    $second = makeMikrotik();

    $first->delete();

    expect($second->fresh()->is_primary)->toBeTrue();
});

it('prefiere un router activo como sustituto', function () {
    $primary  = makeMikrotik();
    $inactive = makeMikrotik(['is_active' => false]);
    $active   = makeMikrotik(['is_active' => true]);

    $primary->delete();

    expect($active->fresh()->is_primary)->toBeTrue()
        ->and($inactive->fresh()->is_primary)->toBeFalse();
});

/*
 * A partir de aquí: las puertas traseras que el observer existe para tapar.
 */

it('promueve un sustituto aunque el primary se borre a través de NetworkDevice', function () {
    $first  = makeMikrotik();
    $second = makeMikrotik();

    // Esto es lo que hará el inventario multi-fabricante: opera sobre la tabla
    // por el modelo genérico, sin saber que la fila es un MikroTik.
    NetworkDevice::findOrFail($first->id)->delete();

    expect($second->fresh()->is_primary)->toBeTrue();
});

it('mantiene un único primary aunque la promoción entre por NetworkDevice', function () {
    $first  = makeMikrotik();
    $second = makeMikrotik();

    $device = NetworkDevice::findOrFail($second->id);
    $device->is_primary = true;
    $device->save();

    expect($first->fresh()->is_primary)->toBeFalse()
        ->and(MikrotikRouter::where('is_primary', true)->count())->toBe(1);
});

it('no aplica el invariante a las antenas', function () {
    $antenna = makeAntenna();

    // Una antena no tiene plano de control: no puede convertirse en el equipo
    // del que el sistema saca sus credenciales de RouterOS.
    expect($antenna->is_primary)->toBeFalse()
        ->and(MikrotikRouter::hasPrimary())->toBeFalse();
});

it('el primer MikroTik nace primary aunque ya existan antenas', function () {
    makeAntenna();
    makeAntenna();

    $router = makeMikrotik();

    expect($router->is_primary)->toBeTrue();
});

it('borrar una antena no toca al router primary', function () {
    $router  = makeMikrotik();
    $antenna = makeAntenna();

    $antenna->delete();

    expect($router->fresh()->is_primary)->toBeTrue();
});

/*
 * El scope de fabricante: que las dos clases no se pisen.
 */

it('MikrotikRouter no ve las antenas', function () {
    makeMikrotik();
    makeAntenna();

    expect(MikrotikRouter::count())->toBe(1)
        ->and(NetworkDevice::count())->toBe(2);
});

it('estampa fabricante y driver al crear un router', function () {
    $router = makeMikrotik();

    $row = NetworkDevice::findOrFail($router->id);

    expect($row->vendor)->toBe(DeviceVendor::MIKROTIK)
        ->and($row->driver)->toBe('routeros')
        ->and($row->role)->toBe(DeviceRole::CORE_ROUTER);
});

it('primaryRouter no devuelve una antena aunque sea el único equipo', function () {
    makeAntenna();

    expect(MikrotikRouter::primaryRouter())->toBeNull();
});
