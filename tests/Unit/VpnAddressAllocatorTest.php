<?php

use App\Enums\ProvisioningStatus;
use App\Models\DeviceProvisioningSession;
use App\Models\MikrotikRouter;
use App\Models\RouterVpnProfile;
use App\Services\Provisioning\ProvisioningSettings;
use App\Services\Provisioning\VpnAddressAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

/**
 * Dos routers con la misma dirección producen un túnel que levanta y enruta
 * mal: el peor fallo posible aquí, porque es silencioso. El asignador tiene que
 * mirar las dos fuentes que retienen una dirección —los perfiles activos y las
 * sesiones de alta todavía en curso— y no solo la primera.
 */

beforeEach(function () {
    $this->allocator = new VpnAddressAllocator(new ProvisioningSettings());
});

function makeSession(array $attributes = []): DeviceProvisioningSession
{
    $agent = App\Models\ProvisioningAgent::create([
        'name' => 'Agente ' . uniqid(), 'role' => 'provisioner', 'is_active' => true,
    ]);

    return DeviceProvisioningSession::create(array_merge([
        'agent_id'         => $agent->id,
        'status'           => ProvisioningStatus::PROVISIONING_ROUTER,
        'detection_method' => 'mndp',
    ], $attributes));
}

it('empieza por la primera dirección libre después de la del servidor', function () {
    // .0 es la red y .1 el servidor, así que el primer router es el .2.
    expect($this->allocator->nextFreeAddress())->toBe('10.77.0.2');
});

it('asigna direcciones distintas a sesiones sucesivas', function () {
    $first  = makeSession();
    $second = makeSession();

    expect($this->allocator->allocateFor($first))->toBe('10.77.0.2')
        ->and($this->allocator->allocateFor($second))->toBe('10.77.0.3');
});

it('respeta las direcciones que retiene una sesión todavía en curso', function () {
    // Este es el caso que un asignador ingenuo se salta: la sesión aún no ha
    // llegado a crear su perfil, así que su dirección solo consta aquí.
    makeSession(['vpn_assigned_ip' => '10.77.0.2']);

    expect($this->allocator->nextFreeAddress())->toBe('10.77.0.3');
});

it('respeta las direcciones de los perfiles ya activos', function () {
    $router = MikrotikRouter::create([
        'name' => 'R1', 'host' => '10.77.0.2', 'username' => 'a', 'password' => 'b',
    ]);

    RouterVpnProfile::create([
        'router_id' => $router->id, 'driver' => 'wireguard', 'interface_name' => 'wg-ispgestor',
        'assigned_ip' => '10.77.0.2', 'router_public_key' => 'k', 'server_public_key' => 's',
        'endpoint_host' => 'vpn.test', 'endpoint_port' => 51820,
        'allowed_ips' => '10.77.0.2/32', 'keepalive' => 25,
        'status' => RouterVpnProfile::STATUS_ACTIVE,
    ]);

    expect($this->allocator->nextFreeAddress())->toBe('10.77.0.3');
});

it('devuelve al pool la dirección de un perfil revocado', function () {
    $router = MikrotikRouter::create([
        'name' => 'R1', 'host' => '10.77.0.2', 'username' => 'a', 'password' => 'b',
    ]);

    $profile = RouterVpnProfile::create([
        'router_id' => $router->id, 'driver' => 'wireguard', 'interface_name' => 'wg-ispgestor',
        'assigned_ip' => '10.77.0.2', 'router_public_key' => 'k', 'server_public_key' => 's',
        'endpoint_host' => 'vpn.test', 'endpoint_port' => 51820,
        'allowed_ips' => '10.77.0.2/32', 'keepalive' => 25,
        'status' => RouterVpnProfile::STATUS_ACTIVE,
    ]);

    expect($this->allocator->nextFreeAddress())->toBe('10.77.0.3');

    $this->allocator->release($profile);

    // Vuelve al pool, pero sin perder el rastro de quién la ocupó.
    expect($this->allocator->nextFreeAddress())->toBe('10.77.0.2')
        ->and($profile->fresh()->released_ip)->toBe('10.77.0.2')
        ->and($profile->fresh()->assigned_ip)->toBeNull()
        ->and($profile->fresh()->status)->toBe(RouterVpnProfile::STATUS_REVOKED);
});

it('ignora las sesiones que ya terminaron', function () {
    makeSession(['vpn_assigned_ip' => '10.77.0.2', 'status' => ProvisioningStatus::ROLLED_BACK]);

    // Una sesión terminada no debe retener la dirección: si no, cada intento
    // fallido consumiría una y la subred se agotaría a base de fracasos.
    expect($this->allocator->nextFreeAddress())->toBe('10.77.0.2');
});

it('es idempotente sobre una sesión que ya tiene dirección', function () {
    $session = makeSession(['vpn_assigned_ip' => '10.77.0.7']);

    expect($this->allocator->allocateFor($session))->toBe('10.77.0.7')
        ->and($this->allocator->allocateFor($session))->toBe('10.77.0.7');
});

it('lanza cuando la subred está agotada', function () {
    config(['provisioning.defaults.vpn_subnet' => '10.77.0.0/30']);

    // Un /30 deja .1 y .2; el .1 es del servidor, así que solo cabe un router.
    makeSession(['vpn_assigned_ip' => '10.77.0.2']);

    expect(fn () => $this->allocator->nextFreeAddress())
        ->toThrow(RuntimeException::class, 'agotada');
});

it('rechaza una subred mal formada', function (string $cidr) {
    config(['provisioning.defaults.vpn_subnet' => $cidr]);

    expect(fn () => $this->allocator->nextFreeAddress())->toThrow(RuntimeException::class);
})->with(['10.77.0.0', 'no-es-una-red', '10.77.0.0/33', '10.77.0.0/4', '999.1.1.0/24']);

it('calcula el rango asignable excluyendo red y difusión', function () {
    $range = $this->allocator->hostRange('192.168.10.0/24');

    expect(long2ip($range['first']))->toBe('192.168.10.1')
        ->and(long2ip($range['last']))->toBe('192.168.10.254');
});

it('reconoce si una dirección pertenece a la subred', function () {
    expect($this->allocator->isWithinSubnet('10.77.0.5'))->toBeTrue()
        ->and($this->allocator->isWithinSubnet('10.77.1.5'))->toBeFalse()
        ->and($this->allocator->isWithinSubnet('192.168.88.1'))->toBeFalse();
});
