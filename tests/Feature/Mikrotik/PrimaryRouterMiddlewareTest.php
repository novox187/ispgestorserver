<?php

use App\Models\MikrotikRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $admin = makeSuperAdminEmployee();
    Sanctum::actingAs($admin, ['*']);
});

it('endpoint /admin/system-bootstrap reporta primary_router_configured=false sin routers', function () {
    $res = $this->getJson('/api/admin/system-bootstrap');
    $res->assertOk()
        ->assertJsonPath('primary_router_configured', false)
        ->assertJsonPath('routers_total', 0)
        ->assertJsonPath('cta.redirect_to', '/red/dispositivos');
});

it('endpoint /admin/system-bootstrap reporta el primary cuando existe', function () {
    MikrotikRouter::create([
        'name'     => 'Principal',
        'host'     => '10.0.0.1',
        'username' => 'admin',
        'password' => 's',
        'port'     => 8728,
    ]);

    $res = $this->getJson('/api/admin/system-bootstrap');
    $res->assertOk()
        ->assertJsonPath('primary_router_configured', true)
        ->assertJsonPath('routers_total', 1)
        ->assertJsonPath('primary_router.host', '10.0.0.1');
});

it('middleware primary_router devuelve 423 sin router configurado', function () {
    $res = $this->getJson('/api/mikrotik/system');
    $res->assertStatus(423)
        ->assertJsonPath('code', 'no_primary_router')
        ->assertJsonPath('redirect_to', '/red/dispositivos');
});

it('middleware primary_router deja pasar cuando hay router configurado', function () {
    MikrotikRouter::create([
        'name' => 'P', 'host' => '10.0.0.1', 'username' => 'admin', 'password' => 's', 'port' => 8728,
    ]);

    $res = $this->getJson('/api/mikrotik/system');
    // No esperamos 200 (la conexión real al router fallaría), pero sí !== 423.
    expect($res->status())->not->toBe(423);
});
