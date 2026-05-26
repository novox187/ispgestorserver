<?php

use App\Models\MikrotikRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marca como primary al primer router creado', function () {
    $router = MikrotikRouter::create([
        'name'     => 'Router único',
        'host'     => '10.0.0.1',
        'port'     => 8728,
        'username' => 'admin',
        'password' => 'secret',
    ]);

    expect($router->fresh()->is_primary)->toBeTrue()
        ->and(MikrotikRouter::hasPrimary())->toBeTrue();
});

it('mantiene un único router primary cuando se marca otro', function () {
    $first = MikrotikRouter::create([
        'name' => 'Primero', 'host' => '10.0.0.1', 'username' => 'admin', 'password' => 's', 'port' => 8728,
    ]);
    $second = MikrotikRouter::create([
        'name' => 'Segundo', 'host' => '10.0.0.2', 'username' => 'admin', 'password' => 's', 'port' => 8728,
    ]);

    expect($first->fresh()->is_primary)->toBeTrue()
        ->and($second->fresh()->is_primary)->toBeFalse();

    $second->is_primary = true;
    $second->save();

    expect($first->fresh()->is_primary)->toBeFalse()
        ->and($second->fresh()->is_primary)->toBeTrue()
        ->and(MikrotikRouter::primary()->count())->toBe(1);
});

it('promueve otro router cuando se elimina el primary', function () {
    $first = MikrotikRouter::create([
        'name' => 'Primero', 'host' => '10.0.0.1', 'username' => 'admin', 'password' => 's', 'port' => 8728,
    ]);
    MikrotikRouter::create([
        'name' => 'Segundo', 'host' => '10.0.0.2', 'username' => 'admin', 'password' => 's', 'port' => 8728,
    ]);

    $first->delete();

    expect(MikrotikRouter::hasPrimary())->toBeTrue()
        ->and(MikrotikRouter::primary()->first()->host)->toBe('10.0.0.2');
});

it('hasPrimary retorna false cuando no hay routers', function () {
    expect(MikrotikRouter::hasPrimary())->toBeFalse()
        ->and(MikrotikRouter::primaryRouter())->toBeNull();
});
