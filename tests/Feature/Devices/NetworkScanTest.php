<?php

use App\Enums\DeviceRole;
use App\Enums\DeviceVendor;
use App\Models\NetworkDevice;
use App\Models\NetworkScan;
use App\Models\NetworkScanFinding;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = makeSuperAdminEmployee();
    $this->agent = makeProvisioningAgent('monitor');
});

function pedirBarrido(array $overrides = [])
{
    return test()->actingAs(test()->admin, 'sanctum')->postJson('/api/admin/network/scans', array_merge([
        'agent_id' => test()->agent['agent']->id,
        'cidr'     => '10.9.0.0/24',
    ], $overrides));
}

function recogerBarridos(array $agent)
{
    $path = '/api/agent/monitoring/scans';

    return test()->get($path, array_merge(
        signedAgentHeaders($agent, 'GET', $path),
        ['Accept' => 'application/json'],
    ));
}

function reportarBarrido(array $agent, int $scanId, array $body)
{
    $path = "/api/agent/monitoring/scans/{$scanId}/report";

    return test()->postJson($path, $body, signedAgentHeaders($agent, 'POST', $path, $body));
}

// ── Petición ─────────────────────────────────────────────────────────────────

it('registra quién pidió el barrido', function () {
    // Un barrido lanza tráfico contra decenas de direcciones de la red del
    // cliente: tiene que quedar constancia de quién lo pidió.
    pedirBarrido()->assertStatus(201);

    expect(NetworkScan::first()->requested_by)->toBe($this->admin->id);
});

it('rechaza un rango que no tiene forma de CIDR', function () {
    pedirBarrido(['cidr' => 'toda-la-red'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'CIDR_INVALID');
});

it('rechaza un prefijo absurdamente amplio', function () {
    pedirBarrido(['cidr' => '10.0.0.0/4'])->assertStatus(422);
});

it('solo un agente de monitoreo puede barrer', function () {
    $provisioner = makeProvisioningAgent('provisioner');

    pedirBarrido(['agent_id' => $provisioner['agent']->id])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'AGENT_ROLE_INVALID');
});

// ── Recogida por el agente ───────────────────────────────────────────────────

it('entrega el barrido al agente y lo marca en curso', function () {
    pedirBarrido();

    $res = recogerBarridos($this->agent)->assertOk();

    expect($res->json('data.scans'))->toHaveCount(1)
        ->and($res->json('data.scans.0.cidr'))->toBe('10.9.0.0/24')
        // Marcarlo al entregarlo evita que la vuelta siguiente lo repita.
        ->and(NetworkScan::first()->status)->toBe(NetworkScan::STATUS_RUNNING);
});

it('no entrega a un agente los barridos de otro', function () {
    $otro = makeProvisioningAgent('monitor');
    pedirBarrido(['agent_id' => $otro['agent']->id]);

    expect(recogerBarridos($this->agent)->json('data.scans'))->toBeEmpty();
});

it('un agente no puede reportar sobre el barrido de otro', function () {
    $otro = makeProvisioningAgent('monitor');
    pedirBarrido(['agent_id' => $otro['agent']->id]);
    $scan = NetworkScan::first();

    reportarBarrido($this->agent, $scan->id, ['status' => 'completed', 'findings' => []])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'SCAN_NOT_FOUND');
});

// ── Hallazgos ────────────────────────────────────────────────────────────────

it('guarda los hallazgos y deduce el fabricante por la OUI', function () {
    pedirBarrido();
    $scan = NetworkScan::first();

    reportarBarrido($this->agent, $scan->id, [
        'status'   => 'completed',
        'findings' => [
            ['ip_address' => '10.9.0.5', 'mac_address' => '24:a4:3c:11:22:33',
             'model' => 'NanoStation M5', 'hostname' => 'Torre-Norte'],
            ['ip_address' => '10.9.0.9', 'mac_address' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'Impresora'],
        ],
    ])->assertOk();

    $porIp = NetworkScanFinding::all()->keyBy('ip_address');

    expect(NetworkScanFinding::count())->toBe(2)
        // La OUI 24:A4:3C es de Ubiquiti.
        ->and($porIp['10.9.0.5']->vendor)->toBe('ubiquiti')
        // La MAC del vecino no es de nadie conocido: se muestra igual, pero sin
        // fingir que sabemos qué es.
        ->and($porIp['10.9.0.9']->vendor)->toBeNull()
        // Se normaliza a mayúsculas: si no, no cruzaría con el inventario.
        ->and($porIp['10.9.0.5']->mac_address)->toBe('24:A4:3C:11:22:33')
        ->and($scan->fresh()->status)->toBe(NetworkScan::STATUS_COMPLETED);
});

it('marca lo que ya está en el inventario en lugar de omitirlo', function () {
    // Al operador le sirve ver que el barrido encontró lo que esperaba, no solo
    // lo que le falta.
    $existente = NetworkDevice::create([
        'name' => 'Enlace Norte', 'vendor' => DeviceVendor::UBIQUITI, 'role' => DeviceRole::BACKHAUL_AP,
        'driver' => 'airos', 'host' => '10.9.0.5', 'mac_address' => '24:A4:3C:11:22:33',
    ]);

    pedirBarrido();
    $scan = NetworkScan::first();

    reportarBarrido($this->agent, $scan->id, [
        'status'   => 'completed',
        'findings' => [['ip_address' => '10.9.0.5', 'mac_address' => '24:A4:3C:11:22:33']],
    ])->assertOk();

    expect(NetworkScanFinding::first()->matched_device_id)->toBe($existente->id);
});

it('un equipo que responde dos veces no se duplica', function () {
    pedirBarrido();
    $scan = NetworkScan::first();

    $finding = ['ip_address' => '10.9.0.5', 'mac_address' => '24:A4:3C:11:22:33'];

    reportarBarrido($this->agent, $scan->id, ['status' => 'completed', 'findings' => [$finding, $finding]])
        ->assertOk();

    expect(NetworkScanFinding::count())->toBe(1);
});

it('registra el rechazo del agente cuando el rango no está en su lista blanca', function () {
    // La comprobación de verdad la hace el agente contra SU configuración; aquí
    // solo se guarda su respuesta para que el operador entienda por qué no salió
    // nada y sepa dónde tocar.
    pedirBarrido(['cidr' => '8.8.8.0/24']);
    $scan = NetworkScan::first();

    reportarBarrido($this->agent, $scan->id, [
        'status'        => 'failed',
        'error_code'    => 'CIDR_NOT_ALLOWED',
        'error_message' => 'El rango 8.8.8.0/24 no está en los rangos permitidos de este agente.',
    ])->assertOk();

    expect($scan->fresh()->status)->toBe(NetworkScan::STATUS_FAILED)
        ->and($scan->fresh()->error_code)->toBe('CIDR_NOT_ALLOWED');
});

it('acepta el firmware largo de airOS sin tirar el barrido entero', function () {
    // El límite era de 40 caracteres, elegido mirando versiones de RouterOS
    // ("7.20.6"). Las de airOS llevan plataforma, chipset, compilación y fecha.
    // En el primer barrido real, cuatro antenas «-licensed» hicieron que el
    // servidor rechazara el informe COMPLETO con un 422: se perdieron los 25
    // equipos, porque el agente no reencola y el barrido quedó colgado.
    $firmware = 'XW.ar934x.v6.1.7-licensed.32555.180523.1625';
    expect(strlen($firmware))->toBeGreaterThan(40);

    pedirBarrido();
    $scan = NetworkScan::first();

    reportarBarrido($this->agent, $scan->id, [
        'status'   => 'completed',
        'findings' => [
            ['ip_address' => '10.10.10.18', 'mac_address' => '74:83:C2:A6:BB:EC',
             'model' => 'LiteBeam M5', 'firmware' => $firmware, 'hostname' => 'DOMINGO GREFA'],
            ['ip_address' => '10.10.10.250', 'mac_address' => 'FC:EC:DA:6C:90:51',
             'model' => 'NanoBeam 5AC', 'firmware' => 'WA.ar934x.v8.7.22.48486.260227.1959'],
        ],
    ])->assertOk();

    expect($scan->fresh()->status)->toBe(NetworkScan::STATUS_COMPLETED)
        ->and(NetworkScanFinding::count())->toBe(2)
        // Y se guarda entero, no recortado a la mitad.
        ->and(NetworkScanFinding::where('ip_address', '10.10.10.18')->value('firmware'))->toBe($firmware);
});

// ── Adopción ─────────────────────────────────────────────────────────────────

it('convierte un hallazgo en un equipo del inventario', function () {
    pedirBarrido();
    $scan = NetworkScan::first();

    reportarBarrido($this->agent, $scan->id, [
        'status'   => 'completed',
        'findings' => [['ip_address' => '10.9.0.5', 'mac_address' => '24:A4:3C:11:22:33',
                        'model' => 'NanoStation M5', 'firmware' => 'XW.v6.3.11']],
    ]);

    $finding = NetworkScanFinding::first();

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/admin/network/scan-findings/{$finding->id}/adopt", [
            'name' => 'Enlace Torre Norte',
            'role' => 'backhaul_ap',
            'username' => 'ubnt',
            'password' => 'ubnt',
        ])->assertStatus(201);

    $device = NetworkDevice::where('host', '10.9.0.5')->first();

    expect($device)->not->toBeNull()
        ->and($device->model)->toBe('NanoStation M5')
        ->and($device->driver)->toBe('airos')
        // Lo sondea el agente que demostró alcanzarlo.
        ->and($device->agent_id)->toBe($this->agent['agent']->id)
        ->and($device->provisioning_source)->toBe('scan')
        // El hallazgo queda enlazado: no se puede adoptar dos veces.
        ->and($finding->fresh()->matched_device_id)->toBe($device->id);
});

it('no deja adoptar dos veces el mismo hallazgo', function () {
    pedirBarrido();
    $scan = NetworkScan::first();
    reportarBarrido($this->agent, $scan->id, [
        'status' => 'completed',
        'findings' => [['ip_address' => '10.9.0.5', 'mac_address' => '24:A4:3C:11:22:33']],
    ]);

    $finding = NetworkScanFinding::first();
    $payload = ['name' => 'Antena', 'role' => 'sector_ap'];

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/admin/network/scan-findings/{$finding->id}/adopt", $payload)->assertStatus(201);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/admin/network/scan-findings/{$finding->id}/adopt", $payload)
        ->assertStatus(422)->assertJsonPath('error.code', 'ALREADY_KNOWN');
});

it('cierra los barridos que el agente nunca reportó', function () {
    // Sin esto quedarían en «ejecutándose» para siempre y el operador no sabría
    // si esperar o volver a pedirlo.
    pedirBarrido();
    recogerBarridos($this->agent);

    NetworkScan::first()->forceFill(['started_at' => now()->subHour()])->save();

    $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/network/scans')->assertOk();

    expect(NetworkScan::first()->status)->toBe(NetworkScan::STATUS_FAILED)
        ->and(NetworkScan::first()->error_code)->toBe('AGENT_SILENT');
});
