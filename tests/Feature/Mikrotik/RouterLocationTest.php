<?php

use App\Models\MikrotikRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Ubicación de un router en el mapa.
 *
 * `latitude`/`longitude` estaban en `$fillable` de `MikrotikRouter` desde que el
 * inventario se unificó, pero no en las reglas de `MikrotikRouterController`, así
 * que `validate()` las descartaba en silencio: el panel las enviaba, el servidor
 * respondía 200 y el router seguía apareciendo en «sin ubicar» del mapa para
 * siempre. Estos casos fijan el contrato para que no vuelva a perderse.
 */
beforeEach(function () {
    Sanctum::actingAs(makeSuperAdminEmployee(), ['*']);
});

it('guarda y devuelve las coordenadas al crear un router', function () {
    $res = $this->postJson('/api/admin/mikrotik-routers', [
        'name'      => 'Torre Norte',
        'host'      => '10.0.0.1',
        'username'  => 'admin',
        'password'  => 'secreto',
        'port'      => 8728,
        'latitude'  => 6.251840,
        'longitude' => -75.563600,
    ]);

    $res->assertCreated();

    $router = MikrotikRouter::firstWhere('host', '10.0.0.1');
    expect((float) $router->latitude)->toBe(6.251840)
        ->and((float) $router->longitude)->toBe(-75.563600);

    // El recurso las expone: sin esto el formulario de edición las perdería.
    $this->getJson('/api/admin/mikrotik-routers/' . $router->id)
        ->assertOk()
        ->assertJsonPath('data.latitude', $router->latitude)
        ->assertJsonPath('data.longitude', $router->longitude);
});

it('permite dejar un router sin ubicar', function () {
    $this->postJson('/api/admin/mikrotik-routers', [
        'name'     => 'Sin ubicar',
        'host'     => '10.0.0.2',
        'username' => 'admin',
        'password' => 'secreto',
    ])->assertCreated();

    $router = MikrotikRouter::firstWhere('host', '10.0.0.2');
    expect($router->latitude)->toBeNull()
        ->and($router->longitude)->toBeNull();
});

it('rechaza coordenadas fuera de rango', function () {
    $this->postJson('/api/admin/mikrotik-routers', [
        'name'      => 'Imposible',
        'host'      => '10.0.0.3',
        'username'  => 'admin',
        'password'  => 'secreto',
        'latitude'  => 91,
        'longitude' => -181,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['latitude', 'longitude']);
});

it('actualiza las coordenadas de un router existente', function () {
    $router = MikrotikRouter::create([
        'name'     => 'Principal',
        'host'     => '10.0.0.4',
        'username' => 'admin',
        'password' => 'secreto',
    ]);

    $this->putJson('/api/admin/mikrotik-routers/' . $router->id, [
        'latitude'  => 6.2,
        'longitude' => -75.6,
    ])->assertOk();

    $router->refresh();
    expect((float) $router->latitude)->toBe(6.2)
        ->and((float) $router->longitude)->toBe(-75.6);
});
