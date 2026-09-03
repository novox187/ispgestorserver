<?php

use App\Models\Client;
use App\Models\NetworkDevice;
use App\Models\NetworkLink;
use App\Models\NetworkScan;
use App\Models\NetworkScanFinding;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Alta de un hallazgo: vínculo con el abonado y enlace del mapa.
 *
 * Lo que se protege aquí es que el descubrimiento sirva para las dos cosas que
 * el operador necesita hacer con lo que encuentra: si es el equipo de un
 * cliente, atarlo a su ficha; si es infraestructura, dejar registrado a qué
 * está conectado para que salga en el mapa.
 */
beforeEach(function () {
    $this->admin = makeSuperAdminEmployee();
    $this->agent = makeProvisioningAgent('monitor');

    $this->scan = NetworkScan::create([
        'agent_id' => $this->agent['agent']->id,
        'cidr'     => '10.10.10.0/24',
        'status'   => NetworkScan::STATUS_COMPLETED,
    ]);
});

function hallazgo(array $overrides = []): NetworkScanFinding
{
    return NetworkScanFinding::create(array_merge([
        'scan_id'    => test()->scan->id,
        'source'     => NetworkScanFinding::SOURCE_SWEEP,
        'ip_address' => '10.10.10.57',
        'mac_address' => '74:AC:B9:82:20:52',
        'vendor'     => 'ubiquiti',
        'model'      => 'LiteBeam M5',
        'hostname'   => 'VICENTE SANCHEZ',
        'created_at' => now(),
    ], $overrides));
}

function adoptar(NetworkScanFinding $f, array $body = [])
{
    return test()->actingAs(test()->admin, 'sanctum')
        ->postJson("/api/admin/network/scan-findings/{$f->id}/adopt", array_merge([
            'name' => 'Equipo de prueba',
            'role' => 'cpe',
        ], $body));
}

// ── Sugerencia del abonado ───────────────────────────────────────────────────

it('propone al abonado por su IP, que es la señal exacta', function () {
    // `clients.ip` es la dirección con la que se le factura: si el equipo
    // responde ahí, es su equipo. No hay que adivinar nada.
    $cliente = Client::factory()->create(['ip' => '10.10.10.57', 'full_name' => 'Otro Nombre']);
    hallazgo();

    $res = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/network/scans/{$this->scan->id}");

    $res->assertOk()
        ->assertJsonPath('data.findings.0.suggested_client_id', $cliente->id)
        ->assertJsonPath('data.findings.0.suggested_client_reason', 'ip');
});

it('propone al abonado por el nombre cuando la IP no dice nada', function () {
    // Los instaladores bautizan la antena con el nombre del cliente. Es una
    // pista buena, pero solo se usa si la IP no resolvió.
    $cliente = Client::factory()->create(['ip' => '0.0.0.0', 'full_name' => 'Vicente Sánchez']);
    hallazgo();

    $res = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/network/scans/{$this->scan->id}");

    $res->assertJsonPath('data.findings.0.suggested_client_id', $cliente->id)
        ->assertJsonPath('data.findings.0.suggested_client_reason', 'name');
});

it('no propone a nadie si dos abonados se llaman igual', function () {
    // Elegir uno al azar sería peor que no proponer: el operador confirmaría
    // sin sospechar y el equipo quedaría atado a la ficha equivocada.
    Client::factory()->create(['ip' => '0.0.0.0', 'full_name' => 'VICENTE SANCHEZ']);
    Client::factory()->create(['ip' => '0.0.0.0', 'full_name' => 'Vicente Sanchez']);
    hallazgo();

    $res = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/network/scans/{$this->scan->id}");

    $res->assertJsonPath('data.findings.0.suggested_client_id', null);
});

it('la IP sin asignar no empareja con nadie', function () {
    Client::factory()->create(['ip' => '0.0.0.0', 'full_name' => 'Nadie']);
    hallazgo(['ip_address' => '0.0.0.0', 'hostname' => null]);

    $res = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/network/scans/{$this->scan->id}");

    $res->assertJsonPath('data.findings.0.suggested_client_id', null);
});

// ── Alta ─────────────────────────────────────────────────────────────────────

it('vincula el equipo al abonado al darlo de alta', function () {
    $cliente = Client::factory()->create(['ip' => '10.10.10.57']);

    adoptar(hallazgo(), ['client_id' => $cliente->id])->assertStatus(201);

    $device = NetworkDevice::where('host', '10.10.10.57')->first();

    expect($device->client_id)->toBe($cliente->id)
        ->and($cliente->fresh()->networkDevices)->toHaveCount(1);
});

it('rechaza vincular un cliente a infraestructura', function () {
    // Una antena sectorial de la torre da servicio a muchos abonados: no es «de»
    // ninguno, y atarla a uno haría creer que su avería afecta solo a esa ficha.
    $cliente = Client::factory()->create();

    adoptar(hallazgo(), ['client_id' => $cliente->id, 'role' => 'sector_ap'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'CLIENT_ONLY_ON_CPE');
});

it('avisa cuando la IP del equipo no es la que factura al cliente', function () {
    // Dos verdades divergentes: una de las dos está mal, y la de `clients.ip`
    // es la que decide a quién se le corta el servicio por impago.
    $cliente = Client::factory()->create(['ip' => '10.10.10.99']);

    $res = adoptar(hallazgo(), ['client_id' => $cliente->id])->assertStatus(201);

    expect($res->json('data.ip_warning'))->toContain('10.10.10.57')
        ->and($res->json('data.ip_warning'))->toContain('10.10.10.99');
});

it('no avisa cuando las dos IP coinciden', function () {
    $cliente = Client::factory()->create(['ip' => '10.10.10.57']);

    $res = adoptar(hallazgo(), ['client_id' => $cliente->id])->assertStatus(201);

    expect($res->json('data.ip_warning'))->toBeNull();
});

// ── Enlace del mapa ──────────────────────────────────────────────────────────

it('registra el enlace solo, sin preguntárselo al operador', function () {
    // Si un router ve a la antena en su tabla de vecinos es que están
    // conectados. Pedir ese dato a mano sería pedir algo que ya se sabe.
    $router = NetworkDevice::create([
        'name' => 'Router Principal', 'vendor' => 'mikrotik', 'role' => 'core_router',
        'driver' => 'routeros', 'host' => '10.0.0.3', 'is_active' => true,
    ]);

    $f = hallazgo([
        'source' => NetworkScanFinding::SOURCE_NEIGHBOR,
        'discovered_via_device_id' => $router->id,
        'remote_interface' => 'ether3',
    ]);

    $res = adoptar($f)->assertStatus(201);
    $device = NetworkDevice::find($res->json('data.device_id'));

    $enlace = NetworkLink::where('a_device_id', min($router->id, $device->id))
        ->where('b_device_id', max($router->id, $device->id))
        ->first();

    expect($enlace)->not->toBeNull()
        ->and($enlace->discovery_source)->toBe('neighbor')
        ->and($res->json('data.link_id'))->toBe($enlace->id);
});

it('un hallazgo que solo vio el barrido UDP no inventa un enlace', function () {
    // Sin saber quién lo ve, no hay extremo con el que enlazarlo. El mapa lo
    // completa después el descubrimiento de topología.
    $res = adoptar(hallazgo())->assertStatus(201);

    expect($res->json('data.link_id'))->toBeNull()
        ->and(NetworkLink::count())->toBe(0);
});
