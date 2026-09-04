<?php

use App\Enums\DeviceRole;
use App\Enums\DeviceVendor;
use App\Models\DeviceMetricHourly;
use App\Models\DeviceMetricSample;
use App\Models\NetworkDevice;
use App\Models\NetworkLink;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Lo que el panel de monitoreo necesita para dejar de ser una lista de dos
 * números por equipo.
 *
 * El sistema ya guardaba CPU, memoria, caudal y calidad del enlace; lo que no
 * había era por dónde sacarlos, así que el operador terminaba abriendo la web de
 * la antena para ver lo que el sistema ya sabía.
 *
 * Los dos casos que de verdad importan aquí son el N+1 del listado —cientos de
 * antenas, una consulta por cada una habría sido inaceptable— y la frontera de
 * permisos, que es la misma del resto del inventario.
 */
beforeEach(function () {
    $this->admin = makeSuperAdminEmployee();
});

function antenaConLectura(array $sample = [], array $overrides = []): NetworkDevice
{
    static $n = 0;
    $n++;

    $device = NetworkDevice::create(array_merge([
        'name'     => "Antena {$n}",
        'vendor'   => DeviceVendor::UBIQUITI,
        'role'     => DeviceRole::CPE,
        'driver'   => 'airos',
        'host'     => "10.9.1.{$n}",
        'username' => 'ubnt',
        'password' => 'ubnt',
    ], $overrides));

    if ($sample !== []) {
        DeviceMetricSample::create(array_merge([
            'device_id'  => $device->id,
            'sampled_at' => now(),
        ], $sample));
    }

    return $device;
}

// ── Listado ──────────────────────────────────────────────────────────────────

it('sirve la última lectura completa en el listado', function () {
    antenaConLectura([
        'cpu_load_percent'   => 4.5,
        'memory_free_bytes'  => 40_000_000,
        'memory_total_bytes' => 65_000_000,
        'signal_dbm'         => -85,
        'noise_floor_dbm'    => -98,
        'snr_db'             => 13,
        'uptime_seconds'     => 861_854,
        'tx_throughput_kbps' => 193,
    ]);

    $res = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/api/admin/network/devices')
        ->assertOk();

    expect($res->json('data.0.telemetry.cpu_load_percent'))->toBe(4.5)
        // El porcentaje de memoria se calcula en el servidor: es la misma cuenta
        // en la tarjeta, en la ficha y en el mapa, y repetirla es la forma
        // habitual de que acaben discrepando.
        ->and($res->json('data.0.telemetry.memory_used_percent'))->toBe(38.5)
        ->and($res->json('data.0.telemetry.uptime_seconds'))->toBe(861854)
        ->and($res->json('data.0.telemetry.tx_throughput_kbps'))->toBe(193);
});

it('no dispara una consulta por equipo para traer su lectura', function () {
    // Con cientos de antenas en el parque, un N+1 aquí convierte la pantalla en
    // inservible justo cuando más equipos hay que vigilar.
    collect(range(1, 5))->each(fn () => antenaConLectura(['signal_dbm' => -60]));

    \Illuminate\Support\Facades\DB::enableQueryLog();

    $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/network/devices')->assertOk();

    $sobreMuestras = collect(\Illuminate\Support\Facades\DB::getQueryLog())
        ->filter(fn ($q) => str_contains($q['query'], 'device_metric_samples'))
        ->count();

    expect($sobreMuestras)->toBe(1);
});

it('devuelve telemetría nula, no cero, cuando el equipo aún no ha reportado', function () {
    // Un cero en la señal se lee como un enlace muerto; el equipo recién dado de
    // alta simplemente todavía no ha dicho nada.
    antenaConLectura();

    $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/network/devices');

    expect($res->json('data.0.telemetry'))->toBeNull();
});

// ── Ficha ────────────────────────────────────────────────────────────────────

it('devuelve la serie al detalle en la ventana corta', function () {
    $antena = antenaConLectura();

    foreach ([30, 20, 10] as $minutos) {
        DeviceMetricSample::create([
            'device_id'   => $antena->id,
            'sampled_at'  => now()->subMinutes($minutos),
            'signal_dbm'  => -70 - $minutos,
            'ccq_percent' => 80,
        ]);
    }

    $res = $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/admin/network/devices/{$antena->id}/metrics?hours=6")
        ->assertOk();

    expect($res->json('data.history.resolution'))->toBe('sample')
        ->and($res->json('data.history.points'))->toHaveCount(3)
        // En orden ascendente: un gráfico se dibuja de izquierda a derecha.
        ->and($res->json('data.history.points.0.signal'))->toBe(-100)
        ->and($res->json('data.history.points.2.signal'))->toBe(-80);
});

it('cambia al resumen horario cuando se pide más de dos días', function () {
    // Pasadas dos semanas el detalle ya está podado; lo que queda del enlace es
    // el resumen por hora, con mínimo y máximo, que es donde se ve la
    // degradación lenta.
    $antena = antenaConLectura();

    DeviceMetricHourly::create([
        'device_id'      => $antena->id,
        'bucket_hour'    => now()->subDays(3)->startOfHour(),
        'sample_count'   => 12,
        'signal_min_dbm' => -92,
        'signal_avg_dbm' => -81.5,
        'signal_max_dbm' => -74,
    ]);

    $res = $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/admin/network/devices/{$antena->id}/metrics?hours=168")
        ->assertOk();

    expect($res->json('data.history.resolution'))->toBe('hourly')
        ->and($res->json('data.history.points.0.signal_min'))->toBe(-92)
        ->and($res->json('data.history.points.0.signal_max'))->toBe(-74);
});

it('trae al vecino sea cual sea el extremo por el que esté guardado el enlace', function () {
    // `network_links` normaliza el orden de los extremos —el id menor va en
    // `a_device_id`—, así que mirar un solo lado dejaría fuera a la mitad de los
    // vecinos de cualquier equipo.
    $sector = antenaConLectura([], ['name' => 'Sector Norte', 'role' => DeviceRole::SECTOR_AP]);
    $cpe    = antenaConLectura([], ['name' => 'CPE Pérez']);

    NetworkLink::create([
        'a_device_id'      => min($sector->id, $cpe->id),
        'b_device_id'      => max($sector->id, $cpe->id),
        'type'             => 'wireless_ptmp',
        'status'           => NetworkLink::STATUS_DISCOVERED,
        'discovery_source' => NetworkLink::SOURCE_AIROS_STATION,
        'last_seen_at'     => now(),
    ]);

    $desdeCpe = $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/admin/network/devices/{$cpe->id}/metrics")->assertOk();
    $desdeSector = $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/admin/network/devices/{$sector->id}/metrics")->assertOk();

    expect($desdeCpe->json('data.peers.0.name'))->toBe('Sector Norte')
        ->and($desdeSector->json('data.peers.0.name'))->toBe('CPE Pérez');
});

it('vincula la ficha con el abonado dueño del equipo', function () {
    // Cuando cae un CPE, la primera pregunta es de quién es.
    $cliente = \App\Models\Client::factory()->create(['full_name' => 'Jois Román']);
    $antena  = antenaConLectura([], ['client_id' => $cliente->id]);

    $res = $this->actingAs($this->admin, 'sanctum')
        ->getJson("/api/admin/network/devices/{$antena->id}/metrics")->assertOk();

    expect($res->json('data.context.client.name'))->toBe('Jois Román');
});

/**
 * Empleado que puede entrar al panel pero no ver el módulo de red.
 *
 * Con nombre propio y no reutilizando el helper de `NetworkDeviceCrudTest`:
 * Pest carga todos los ficheros de la suite en el mismo proceso y dos funciones
 * globales con el mismo nombre revientan la ejecución entera.
 */
function empleadoSinAccesoARed(): \App\Models\Employee
{
    $role = \App\Models\Role::create([
        'nombre' => 'Cobros', 'slug' => 'cobros-' . uniqid(), 'descripcion' => '',
    ]);

    $permission = \App\Models\Permission::firstOrCreate(
        ['slug' => 'clientes.ver'],
        ['nombre' => 'clientes.ver', 'descripcion' => ''],
    );
    $role->permissions()->attach($permission->id);

    return \App\Models\Employee::factory()->create(['role_id' => $role->id]);
}

it('exige permiso de lectura del módulo de red', function () {
    $antena   = antenaConLectura();
    $extraño  = empleadoSinAccesoARed();

    $this->actingAs($extraño, 'sanctum')
        ->getJson("/api/admin/network/devices/{$antena->id}/metrics")
        ->assertForbidden();
});
