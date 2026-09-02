<?php

use App\Enums\DeviceRole;
use App\Enums\DeviceVendor;
use App\Jobs\DiscoverTopologyLinksJob;
use App\Models\NetworkDevice;
use App\Models\NetworkLink;
use App\Models\NetworkSite;
use App\Services\Devices\TopologyRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = makeSuperAdminEmployee();
});

function antena(string $nombre, string $mac, array $overrides = []): NetworkDevice
{
    static $n = 0;
    $n++;

    return NetworkDevice::create(array_merge([
        'name'        => $nombre,
        'vendor'      => DeviceVendor::UBIQUITI,
        'role'        => DeviceRole::BACKHAUL_AP,
        'driver'      => 'airos',
        'host'        => "10.9.0.{$n}",
        'mac_address' => $mac,
    ], $overrides));
}

// ── Registro de enlaces ──────────────────────────────────────────────────────

it('normaliza el orden de los extremos', function () {
    // Un enlace entre A y B es el mismo que entre B y A. Sin normalizar, el
    // descubrimiento crearía dos filas —una por cada extremo que lo reporte— y
    // el mapa dibujaría dos líneas superpuestas.
    $a = antena('Norte', '24:A4:3C:00:00:01');
    $b = antena('Sur', '24:A4:3C:00:00:02');

    NetworkLink::record($b->id, $a->id, NetworkLink::SOURCE_MANUAL);
    NetworkLink::record($a->id, $b->id, NetworkLink::SOURCE_MANUAL);

    expect(NetworkLink::count())->toBe(1)
        ->and(NetworkLink::first()->a_device_id)->toBe(min($a->id, $b->id));
});

it('no enlaza un equipo consigo mismo', function () {
    $a = antena('Norte', '24:A4:3C:00:00:01');

    expect(NetworkLink::record($a->id, $a->id, NetworkLink::SOURCE_MANUAL))->toBeNull()
        ->and(NetworkLink::count())->toBe(0);
});

it('volver a descubrir un enlace confirmado no lo devuelve a descubierto', function () {
    // Que el descubrimiento lo vuelva a ver no puede deshacer la decisión que
    // tomó un operador.
    $a = antena('Norte', '24:A4:3C:00:00:01');
    $b = antena('Sur', '24:A4:3C:00:00:02');

    $link = NetworkLink::record($a->id, $b->id, NetworkLink::SOURCE_NEIGHBOR);
    $link->update(['status' => NetworkLink::STATUS_CONFIRMED]);

    NetworkLink::record($a->id, $b->id, NetworkLink::SOURCE_NEIGHBOR);

    expect($link->fresh()->status)->toBe(NetworkLink::STATUS_CONFIRMED)
        ->and($link->fresh()->last_seen_at)->not->toBeNull();
});

// ── Cruce por MAC ────────────────────────────────────────────────────────────

it('cruza las MAC descubiertas con el inventario sin importar el formato', function () {
    // RouterOS las da en mayúsculas con dos puntos y airOS a veces en
    // minúsculas. Sin normalizar, el enlace simplemente no aparecería —sin error
    // que lo delate.
    $ap  = antena('Sector', '24:A4:3C:00:00:01');
    $sta = antena('Cliente', '24:A4:3C:00:00:02', ['role' => DeviceRole::CPE]);

    $registrados = app(TopologyRecorder::class)
        ->recordPeers($ap, ['24-a4-3c-00-00-02'], NetworkLink::SOURCE_AIROS_STATION);

    expect($registrados)->toBe(1)
        ->and(NetworkLink::count())->toBe(1);
});

it('ignora las MAC que no están en el inventario', function () {
    // Un router ve por LLDP el switch de la oficina y el portátil del técnico.
    // Crear dispositivos con eso llenaría el inventario de cosas que nadie
    // quiere gestionar; lo que falte se descubre con un barrido.
    $ap = antena('Sector', '24:A4:3C:00:00:01');

    $registrados = app(TopologyRecorder::class)
        ->recordPeers($ap, ['AA:BB:CC:DD:EE:FF'], NetworkLink::SOURCE_NEIGHBOR);

    expect($registrados)->toBe(0)
        ->and(NetworkLink::count())->toBe(0)
        ->and(NetworkDevice::count())->toBe(1);
});

it('descarta una MAC mal formada sin romper el resto', function () {
    $ap  = antena('Sector', '24:A4:3C:00:00:01');
    $sta = antena('Cliente', '24:A4:3C:00:00:02', ['role' => DeviceRole::CPE]);

    $registrados = app(TopologyRecorder::class)
        ->recordPeers($ap, ['esto-no-es-una-mac', '24:A4:3C:00:00:02'], NetworkLink::SOURCE_NEIGHBOR);

    expect($registrados)->toBe(1);
});

// ── Sitios ───────────────────────────────────────────────────────────────────

it('da de alta un sitio con coordenadas', function () {
    $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/network/sites', [
        'name' => 'Torre Norte', 'type' => 'tower',
        'latitude' => -0.1807, 'longitude' => -78.4678, 'elevation_m' => 2850,
    ])->assertStatus(201);

    expect(NetworkSite::first()->name)->toBe('Torre Norte');
});

it('impide que un sitio dependa de sí mismo', function () {
    // Un ciclo haría que el mapa entrara en bucle al plegar por zonas.
    $sitio = NetworkSite::create(['name' => 'Torre', 'type' => 'tower']);

    $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/admin/network/sites/{$sitio->id}", ['parent_site_id' => $sitio->id])
        ->assertStatus(422)->assertJsonPath('error.code', 'SITE_CYCLE');
});

it('impide un ciclo indirecto entre sitios', function () {
    $padre = NetworkSite::create(['name' => 'POP', 'type' => 'pop']);
    $hijo  = NetworkSite::create(['name' => 'Torre', 'type' => 'tower', 'parent_site_id' => $padre->id]);

    $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/admin/network/sites/{$padre->id}", ['parent_site_id' => $hijo->id])
        ->assertStatus(422);
});

it('borrar un sitio deja sus equipos sin ubicar, no los borra', function () {
    $sitio = NetworkSite::create(['name' => 'Torre', 'type' => 'tower']);
    $a = antena('Norte', '24:A4:3C:00:00:01', ['site_id' => $sitio->id]);

    $this->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/admin/network/sites/{$sitio->id}")->assertOk();

    expect($a->fresh())->not->toBeNull()
        ->and($a->fresh()->site_id)->toBeNull();
});

// ── Mapa ─────────────────────────────────────────────────────────────────────

it('devuelve sitios, equipos y enlaces en una sola llamada', function () {
    $sitio = NetworkSite::create([
        'name' => 'Torre Norte', 'type' => 'tower', 'latitude' => -0.18, 'longitude' => -78.46,
    ]);
    $a = antena('Norte', '24:A4:3C:00:00:01', ['site_id' => $sitio->id]);
    $b = antena('Sur', '24:A4:3C:00:00:02', ['latitude' => -0.19, 'longitude' => -78.47]);
    NetworkLink::record($a->id, $b->id, NetworkLink::SOURCE_NEIGHBOR);

    $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/network/map')->assertOk();

    expect($res->json('data.sites'))->toHaveCount(1)
        ->and($res->json('data.devices'))->toHaveCount(2)
        ->and($res->json('data.links'))->toHaveCount(1);
});

it('un equipo sin coordenadas propias hereda las de su sitio', function () {
    $sitio = NetworkSite::create([
        'name' => 'Torre', 'type' => 'tower', 'latitude' => -0.18, 'longitude' => -78.46,
    ]);
    antena('Norte', '24:A4:3C:00:00:01', ['site_id' => $sitio->id]);

    $device = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/admin/network/map')->json('data.devices.0');

    expect((float) $device['latitude'])->toBe(-0.18)
        // Se distingue de tener coordenadas propias: mover el sitio movería a
        // este equipo y no a los que las tienen.
        ->and($device['located_by'])->toBe('site');
});

it('los equipos sin ubicar se devuelven marcados, no se esconden', function () {
    // Un equipo sin ubicar es algo que hay que arreglar, no algo que ignorar.
    antena('Sin ubicar', '24:A4:3C:00:00:09');

    $device = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/admin/network/map')->json('data.devices.0');

    expect($device['located_by'])->toBeNull()
        ->and($device['latitude'])->toBeNull();
});

it('la calidad del enlace se deriva del peor de sus extremos', function () {
    // No se guarda en la fila del enlace: mantenerla sincronizada con cada
    // muestra acabaría desfasándola.
    $a = antena('Norte', '24:A4:3C:00:00:01', ['latitude' => -0.18, 'longitude' => -78.46]);
    $b = antena('Sur', '24:A4:3C:00:00:02', ['latitude' => -0.19, 'longitude' => -78.47]);
    $a->forceFill(['last_signal_dbm' => -60])->save();
    $b->forceFill(['last_signal_dbm' => -84])->save();
    NetworkLink::record($a->id, $b->id, NetworkLink::SOURCE_NEIGHBOR);

    $link = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/admin/network/map')->json('data.links.0');

    expect($link['signal_dbm'])->toBe(-84);
});

it('el mapa omite los enlaces cuyos extremos no se pueden situar', function () {
    // Dibujar una línea hacia un nodo que no existe en el lienzo es peor que no
    // dibujarla.
    $a = antena('Ubicada', '24:A4:3C:00:00:01', ['latitude' => -0.18, 'longitude' => -78.46]);
    $b = antena('Sin ubicar', '24:A4:3C:00:00:02');
    NetworkLink::record($a->id, $b->id, NetworkLink::SOURCE_NEIGHBOR);

    $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/network/map');

    expect($res->json('data.links'))->toBeEmpty()
        ->and($res->json('data.devices'))->toHaveCount(2);
});

// ── Confirmación ─────────────────────────────────────────────────────────────

it('un enlace declarado a mano nace confirmado', function () {
    // No hay nada que confirmar sobre lo que una persona acaba de afirmar.
    $a = antena('Norte', '24:A4:3C:00:00:01');
    $b = antena('Sur', '24:A4:3C:00:00:02');

    $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/network/links', [
        'a_device_id' => $a->id, 'b_device_id' => $b->id, 'type' => 'wireless_ptp',
    ])->assertStatus(201);

    expect($res->json('data.status'))->toBe(NetworkLink::STATUS_CONFIRMED);
});

it('el operador puede confirmar o archivar un enlace descubierto', function () {
    $a = antena('Norte', '24:A4:3C:00:00:01');
    $b = antena('Sur', '24:A4:3C:00:00:02');
    $link = NetworkLink::record($a->id, $b->id, NetworkLink::SOURCE_NEIGHBOR);

    $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/admin/network/links/{$link->id}", ['status' => 'archived'])->assertOk();

    expect($link->fresh()->status)->toBe(NetworkLink::STATUS_ARCHIVED);

    // Y los archivados desaparecen del mapa sin borrarse.
    expect(NetworkLink::visible()->count())->toBe(0)
        ->and(NetworkLink::count())->toBe(1);
});

it('borrar un equipo se lleva sus enlaces', function () {
    $a = antena('Norte', '24:A4:3C:00:00:01');
    $b = antena('Sur', '24:A4:3C:00:00:02');
    NetworkLink::record($a->id, $b->id, NetworkLink::SOURCE_NEIGHBOR);

    $a->delete();

    // Un enlace con un extremo inexistente no significa nada.
    expect(NetworkLink::count())->toBe(0);
});

it('el descubrimiento no consulta los equipos que sondea un agente', function () {
    // El servidor no alcanza la LAN del cliente: intentarlo solo gastaría el
    // ciclo en timeouts. Sus enlaces llegan por la ingesta de telemetría.
    $agent = makeProvisioningAgent('monitor');
    antena('Tras el agente', '24:A4:3C:00:00:01', [
        'host' => '192.0.2.1', 'agent_id' => $agent['agent']->id,
    ]);

    $inicio = microtime(true);
    (new DiscoverTopologyLinksJob())->handle(
        app(App\Services\Devices\DeviceDriverRegistry::class),
        app(TopologyRecorder::class),
    );

    // Si lo hubiera consultado, el timeout haría que esto tardara segundos.
    expect(microtime(true) - $inicio)->toBeLessThan(2.0);
});
