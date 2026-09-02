<?php

use App\Enums\DeviceRole;
use App\Enums\DeviceVendor;
use App\Models\MikrotikRouter;
use App\Models\NetworkDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = makeSuperAdminEmployee();
});

/**
 * Empleado con un juego de permisos acotado.
 *
 * No hay helper global para esto: los tests existentes solo necesitaban un
 * super_admin, que se salta el middleware. Aquí hace falta lo contrario —alguien
 * que pueda mirar pero no tocar— para comprobar que la frontera existe.
 */
function makeEmployeeWithPermissions(array $slugs): \App\Models\Employee
{
    $role = \App\Models\Role::create([
        'nombre' => 'Técnico', 'slug' => 'tecnico-' . uniqid(), 'descripcion' => '',
    ]);

    foreach ($slugs as $slug) {
        $permission = \App\Models\Permission::firstOrCreate(
            ['slug' => $slug],
            ['nombre' => $slug, 'descripcion' => ''],
        );
        $role->permissions()->attach($permission->id);
    }

    return \App\Models\Employee::factory()->create(['role_id' => $role->id]);
}

it('da de alta una antena por IP', function () {
    // La vía prevista para las antenas: el operador teclea dirección y
    // credenciales. El barrido de descubrimiento acabará en este mismo sitio.
    $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/network/devices', [
        'name'     => 'Enlace Torre Norte',
        'vendor'   => 'ubiquiti',
        'role'     => 'backhaul_ap',
        'host'     => '10.9.0.5',
        'username' => 'ubnt',
        'password' => 'ubnt',
    ])->assertStatus(201);

    expect($res->json('data.driver'))->toBe('airos')
        ->and($res->json('data.has_radio'))->toBeTrue()
        // La contraseña no vuelve nunca en la respuesta.
        ->and($res->json('data'))->not->toHaveKey('password');
});

it('propone el driver por defecto del fabricante', function () {
    $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/network/devices', [
        'name' => 'A', 'vendor' => 'ubiquiti', 'role' => 'cpe', 'host' => '10.9.0.6',
    ])->assertStatus(201);

    expect(NetworkDevice::where('host', '10.9.0.6')->first()->driver)->toBe('airos');
});

it('no deja crear un MikroTik por esta vía', function () {
    // Crear un router exige decisiones que este controlador no toma —quién es el
    // primary, qué pasa con el túnel— y que su propio módulo ya resuelve. Dos
    // puertas de escritura sobre la misma fila acabarían separándose.
    $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/network/devices', [
        'name' => 'Router', 'vendor' => 'mikrotik', 'role' => 'core_router', 'host' => '10.0.0.1',
    ])->assertStatus(422)->assertJsonPath('error.code', 'MIKROTIK_MANAGED_ELSEWHERE');
});

it('lista el parque entero y lo marca como editable o no', function () {
    MikrotikRouter::create(['name' => 'Router', 'host' => '10.0.0.1', 'username' => 'a', 'password' => 'b']);
    NetworkDevice::create([
        'name' => 'Antena', 'vendor' => DeviceVendor::UBIQUITI, 'role' => DeviceRole::SECTOR_AP,
        'driver' => 'airos', 'host' => '10.9.0.1', 'username' => 'ubnt', 'password' => 'ubnt',
    ]);

    $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/network/devices')->assertOk();

    $porNombre = collect($res->json('data'))->keyBy('name');

    expect($res->json('data'))->toHaveCount(2)
        ->and($porNombre['Router']['editable'])->toBeFalse()
        ->and($porNombre['Antena']['editable'])->toBeTrue()
        ->and($porNombre['Router']['has_radio'])->toBeFalse();
});

it('filtra por fabricante y por infraestructura', function () {
    MikrotikRouter::create(['name' => 'Router', 'host' => '10.0.0.1', 'username' => 'a', 'password' => 'b']);
    NetworkDevice::create([
        'name' => 'Sector', 'vendor' => DeviceVendor::UBIQUITI, 'role' => DeviceRole::SECTOR_AP,
        'driver' => 'airos', 'host' => '10.9.0.1', 'username' => 'u', 'password' => 'p',
    ]);
    NetworkDevice::create([
        'name' => 'CPE cliente', 'vendor' => DeviceVendor::UBIQUITI, 'role' => DeviceRole::CPE,
        'driver' => 'airos', 'host' => '10.9.0.2', 'username' => 'u', 'password' => 'p',
    ]);

    $ubnt = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/admin/network/devices?vendor=ubiquiti')->json('data');

    // La infraestructura excluye las CPE: son dos órdenes de magnitud más
    // equipos y su caída casi nunca es una incidencia de red.
    $infra = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/admin/network/devices?only_infrastructure=1')->json('data');

    expect($ubnt)->toHaveCount(2)
        ->and($infra)->toHaveCount(2)
        ->and(collect($infra)->pluck('name'))->not->toContain('CPE cliente');
});

it('no pisa la contraseña guardada si el formulario la envía vacía', function () {
    $antenna = NetworkDevice::create([
        'name' => 'Antena', 'vendor' => DeviceVendor::UBIQUITI, 'role' => DeviceRole::SECTOR_AP,
        'driver' => 'airos', 'host' => '10.9.0.1', 'username' => 'ubnt', 'password' => 'secreto',
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/admin/network/devices/{$antenna->id}", ['name' => 'Renombrada', 'password' => ''])
        ->assertOk();

    expect($antenna->fresh()->name)->toBe('Renombrada')
        ->and($antenna->fresh()->password)->toBe('secreto');
});

it('rechaza coordenadas imposibles', function () {
    $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/network/devices', [
        'name' => 'A', 'vendor' => 'ubiquiti', 'role' => 'cpe', 'host' => '10.9.0.9',
        'latitude' => 120, 'longitude' => 0,
    ])->assertStatus(422);
});

it('exige permisos para escribir', function () {
    $sinPermisos = makeEmployeeWithPermissions(['mikrotik.ver']);

    $this->actingAs($sinPermisos, 'sanctum')->postJson('/api/admin/network/devices', [
        'name' => 'A', 'vendor' => 'ubiquiti', 'role' => 'cpe', 'host' => '10.9.0.9',
    ])->assertStatus(403);
});
