<?php

use App\Models\AutomationSetting;
use App\Models\DeviceProvisioningSession;
use App\Services\Provisioning\ProvisioningSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * La detección es la puerta de entrada del flujo y lo que decide qué se
 * considera candidato a un alta. Aquí se comprueban las dos cosas que evitan
 * que esa puerta se quede abierta de más: el filtro por fabricante y la
 * normalización de la MAC, que es lo que hace idempotente al endpoint.
 */

beforeEach(function () {
    $this->provisioner = makeProvisioningAgent('provisioner');
});

function detect(\Tests\TestCase $test, array $provisioner, array $body): Illuminate\Testing\TestResponse
{
    $body = array_merge(['detection_method' => 'mndp'], $body);

    return $test->postJson(
        '/api/agent/devices/detected',
        $body,
        signedAgentHeaders($provisioner, 'POST', '/api/agent/devices/detected', $body),
    );
}

it('abre una sesión al detectar un equipo por MNDP', function () {
    detect($this, $this->provisioner, [
        'mac_address'      => '18:FD:74:AA:BB:CC',
        'identity'         => 'MikroTik',
        'board_name'       => 'hEX S',
        'routeros_version' => '7.15.3',
        'link_interface'   => 'eth1',
        'lan_ip'           => '192.168.88.1',
    ])
        ->assertStatus(201)
        ->assertJsonPath('data.created', true)
        ->assertJsonPath('data.status', 'detected');

    $session = DeviceProvisioningSession::first();

    expect($session->mac_address)->toBe('18:FD:74:AA:BB:CC')
        ->and($session->board_name)->toBe('hEX S')
        ->and($session->link_interface)->toBe('eth1')
        ->and($session->detection_method)->toBe('mndp')
        ->and($session->agent_id)->toBe($this->provisioner['agent']->id);
});

it('normaliza el formato de la MAC', function (string $raw) {
    detect($this, $this->provisioner, ['mac_address' => $raw, 'lan_ip' => '192.168.88.1']);

    // MNDP entrega bytes crudos y la sonda ARP suele dar minúsculas con
    // guiones. Sin normalizar, el mismo equipo abriría una sesión por formato.
    expect(DeviceProvisioningSession::first()->mac_address)->toBe('18:FD:74:AA:BB:CC');
})->with([
    '18:FD:74:AA:BB:CC',
    '18-fd-74-aa-bb-cc',
    '18fd74aabbcc',
    '18:fd:74:aa:bb:cc',
]);

it('descarta un equipo de un fabricante no admitido', function () {
    // Enchufar un portátil en el puerto de aprovisionamiento es normal y no es
    // un intento de intrusión: se descarta sin abrir sesión ni alertar.
    detect($this, $this->provisioner, [
        'mac_address' => 'AA:BB:CC:DD:EE:FF',
        'lan_ip'      => '192.168.88.1',
    ])
        ->assertStatus(202)
        ->assertJsonPath('data.ignored', true)
        ->assertJsonPath('data.code', 'MAC_NOT_ALLOWED');

    expect(DeviceProvisioningSession::count())->toBe(0);
});

it('admite cualquier MAC cuando la lista de prefijos está vacía', function () {
    AutomationSetting::create([
        'key' => ProvisioningSettings::SETTING_KEY, 'name' => 'Alta automática',
        'job_class' => App\Jobs\ExpireStaleProvisioningTasks::class, 'queue' => 'provisioning',
        'enabled' => true, 'schedule_type' => 'every_five_minutes',
        'params' => ['allowed_mac_prefixes' => []],
    ]);

    // Vaciar la lista es una decisión deliberada para bancos de pruebas, no un
    // descuido: se respeta.
    detect($this, $this->provisioner, ['mac_address' => 'AA:BB:CC:DD:EE:FF', 'lan_ip' => '192.168.88.1'])
        ->assertStatus(201);

    expect(DeviceProvisioningSession::count())->toBe(1);
});

it('no abre sesiones cuando el alta automática está desactivada', function () {
    AutomationSetting::create([
        'key' => ProvisioningSettings::SETTING_KEY, 'name' => 'Alta automática',
        'job_class' => App\Jobs\ExpireStaleProvisioningTasks::class, 'queue' => 'provisioning',
        'enabled' => false, 'schedule_type' => 'every_five_minutes', 'params' => [],
    ]);

    detect($this, $this->provisioner, ['mac_address' => '18:FD:74:AA:BB:CC', 'lan_ip' => '192.168.88.1'])
        ->assertStatus(202)
        ->assertJsonPath('data.code', 'PROVISIONING_DISABLED');

    expect(DeviceProvisioningSession::count())->toBe(0);
});

it('enriquece la sesión abierta con lo que aporte un reporte posterior', function () {
    // MNDP no lleva serial; la sonda directa sí. El segundo reporte debe
    // completar lo que faltaba en vez de descartarse.
    detect($this, $this->provisioner, ['mac_address' => '18:FD:74:AA:BB:CC', 'identity' => 'MikroTik']);

    detect($this, $this->provisioner, [
        'detection_method' => 'link_probe',
        'mac_address'      => '18:FD:74:AA:BB:CC',
        'serial_number'    => 'HEX7S0012345',
        'lan_ip'           => '192.168.88.1',
    ])->assertOk()->assertJsonPath('data.created', false);

    $session = DeviceProvisioningSession::first();

    expect(DeviceProvisioningSession::count())->toBe(1)
        ->and($session->serial_number)->toBe('HEX7S0012345')
        ->and($session->lan_ip)->toBe('192.168.88.1')
        // El método de detección original se conserva: es el que explica cómo
        // se encontró el equipo.
        ->and($session->detection_method)->toBe('mndp');
});

it('deduplica también por número de serie', function () {
    detect($this, $this->provisioner, [
        'mac_address'   => '18:FD:74:00:00:01',
        'serial_number' => 'HEX7S0012345',
    ]);

    // El mismo equipo visto por otro puerto de gestión: distinta MAC, mismo
    // serial. No debe abrir un alta paralela.
    detect($this, $this->provisioner, [
        'mac_address'   => '18:FD:74:00:00:02',
        'serial_number' => 'HEX7S0012345',
    ])->assertOk()->assertJsonPath('data.created', false);

    expect(DeviceProvisioningSession::count())->toBe(1);
});

it('abre una sesión nueva cuando la anterior del mismo equipo ya terminó', function () {
    detect($this, $this->provisioner, ['mac_address' => '18:FD:74:AA:BB:CC']);

    DeviceProvisioningSession::first()->markFailed('TEST', 'Terminada para la prueba.');

    detect($this, $this->provisioner, ['mac_address' => '18:FD:74:AA:BB:CC'])
        ->assertStatus(201)
        ->assertJsonPath('data.created', true);

    expect(DeviceProvisioningSession::count())->toBe(2);
});

it('impide que un agente de VPN reporte detecciones', function () {
    $vpnHost = makeProvisioningAgent('vpn_host');

    detect($this, $vpnHost, ['mac_address' => '18:FD:74:AA:BB:CC'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'AGENT_ROLE_FORBIDDEN');

    expect(DeviceProvisioningSession::count())->toBe(0);
});

it('valida el método de detección declarado', function () {
    detect($this, $this->provisioner, [
        'detection_method' => 'telepatia',
        'mac_address'      => '18:FD:74:AA:BB:CC',
    ])->assertStatus(422);
});
