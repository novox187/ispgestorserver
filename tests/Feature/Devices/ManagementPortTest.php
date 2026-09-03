<?php

use App\Enums\DeviceRole;
use App\Enums\DeviceVendor;
use App\Models\NetworkDevice;
use App\Models\NetworkScan;
use App\Models\NetworkScanFinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * El puerto de gestión de cada fabricante.
 *
 * Parece un detalle de configuración y no lo es: la columna `port` arrastra un
 * `default(8728)` de cuando el inventario solo guardaba routers MikroTik, así
 * que toda alta que no fije el puerto deja a la antena Ubiquiti apuntando a la
 * API binaria de RouterOS. El equipo rechaza la conexión y el operador ve un
 * error de red —«could not connect to server»— que le hace buscar el problema
 * en el túnel, en el firewall o en la propia antena.
 *
 * Pasó en producción con una NanoStation loco M5 recién dada de alta.
 */
beforeEach(function () {
    $this->admin = makeSuperAdminEmployee();
});

// ── El fabricante decide el puerto ───────────────────────────────────────────

it('da a cada fabricante el puerto de su plano de gestión', function () {
    expect(DeviceVendor::MIKROTIK->defaultPort())->toBe(8728)
        ->and(DeviceVendor::UBIQUITI->defaultPort())->toBe(443);
});

// ── Alta desde un hallazgo del barrido ───────────────────────────────────────

it('da de alta la antena en su puerto y no en el de MikroTik', function () {
    $agent = makeProvisioningAgent('monitor');

    $scan = NetworkScan::create([
        'agent_id' => $agent['agent']->id,
        'cidr'     => '10.10.10.0/24',
        'status'   => NetworkScan::STATUS_COMPLETED,
    ]);

    $finding = NetworkScanFinding::create([
        'scan_id'     => $scan->id,
        'source'      => NetworkScanFinding::SOURCE_SWEEP,
        'ip_address'  => '10.10.10.236',
        'mac_address' => '74:AC:B9:82:20:52',
        'vendor'      => 'ubiquiti',
        'model'       => 'NanoStation loco M5',
        'created_at'  => now(),
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->postJson("/api/admin/network/scan-findings/{$finding->id}/adopt", [
            'name' => 'AP SOL NACIENTE',
            'role' => 'backhaul_ap',
        ])->assertStatus(201);

    expect(NetworkDevice::where('host', '10.10.10.236')->first()->port)->toBe(443);
});

// ── Alta a mano ──────────────────────────────────────────────────────────────

it('rellena el puerto del fabricante cuando el formulario lo deja vacío', function () {
    // El operador que teclea la IP de una antena no tiene por qué saberse el
    // puerto de su interfaz web; el sistema sí.
    $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/network/devices', [
        'name' => 'Sector Norte', 'vendor' => 'ubiquiti', 'role' => 'sector_ap',
        'host' => '10.9.0.7', 'port' => null,
    ])->assertStatus(201);

    expect(NetworkDevice::where('host', '10.9.0.7')->first()->port)->toBe(443);
});

it('respeta el puerto que el operador escribe', function () {
    // Hay antenas detrás de un NAT o con la web movida a propósito: el valor por
    // defecto es una ayuda, no una imposición.
    $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/network/devices', [
        'name' => 'Tras NAT', 'vendor' => 'ubiquiti', 'role' => 'cpe',
        'host' => '10.9.0.8', 'port' => 8443,
    ])->assertStatus(201);

    expect(NetworkDevice::where('host', '10.9.0.8')->first()->port)->toBe(8443);
});

it('vaciar el puerto al editar vuelve al del fabricante, no revienta', function () {
    // La columna no admite nulos: sin esta red, borrar el campo en el formulario
    // devolvía un error 500 de base de datos.
    $device = NetworkDevice::create([
        'name' => 'Antena', 'vendor' => DeviceVendor::UBIQUITI, 'role' => DeviceRole::CPE,
        'driver' => 'airos', 'host' => '10.9.0.9', 'port' => 8443,
        'username' => 'ubnt', 'password' => 'ubnt',
    ]);

    $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/admin/network/devices/{$device->id}", ['port' => null])
        ->assertOk();

    expect($device->fresh()->port)->toBe(443);
});

// ── Lo que el driver acaba pidiendo ──────────────────────────────────────────

it('pide login.cgi en el puerto del equipo', function (int $puerto, string $esperada) {
    // La prueba de credenciales del panel termina aquí: con 8728 la petición
    // salía a un puerto donde airOS no escucha.
    //
    // Se comprueba con puertos no estándar a propósito. Guzzle normaliza el
    // `:443` de una URL https y lo borra, así que un caso con 443 pasaría igual
    // aunque el driver ignorase la columna por completo.
    $device = NetworkDevice::create([
        'name' => 'Antena', 'vendor' => DeviceVendor::UBIQUITI, 'role' => DeviceRole::CPE,
        'driver' => 'airos', 'host' => '10.10.10.236', 'port' => $puerto,
        'username' => 'ubnt', 'password' => 'ubnt',
    ]);

    Http::fake(['*' => Http::response([], 200)]);

    app(\App\Services\Devices\DeviceDriverRegistry::class)->for($device)?->telemetry($device);

    Http::assertSent(fn ($request) => $request->url() === $esperada);
})->with([
    'la web movida tras un NAT' => [8443, 'https://10.10.10.236:8443/login.cgi'],
    // 80 es el único puerto que además cambia el esquema: airOS sin TLS.
    'airOS en HTTP plano'       => [80, 'http://10.10.10.236/login.cgi'],
    // Y el valor heredado que provocó el fallo: si alguna fila se queda así,
    // que al menos el driver lo lleve donde el operador pueda verlo.
    'el 8728 heredado'          => [8728, 'https://10.10.10.236:8728/login.cgi'],
]);

// ── Las filas que ya estaban mal ─────────────────────────────────────────────

it('la migración corrige las antenas que quedaron en el puerto de MikroTik', function () {
    $malas = collect([8728, 8729])->map(fn ($puerto) => NetworkDevice::create([
        'name' => "Antena {$puerto}", 'vendor' => DeviceVendor::UBIQUITI, 'role' => DeviceRole::CPE,
        'driver' => 'airos', 'host' => "10.9.1.{$puerto}", 'port' => $puerto,
        'username' => 'u', 'password' => 'p',
    ]));

    // Un puerto deliberado no se toca: puede ser una antena tras un NAT.
    $deliberada = NetworkDevice::create([
        'name' => 'Tras NAT', 'vendor' => DeviceVendor::UBIQUITI, 'role' => DeviceRole::CPE,
        'driver' => 'airos', 'host' => '10.9.1.1', 'port' => 8443,
        'username' => 'u', 'password' => 'p',
    ]);

    // Y un MikroTik en 8728 está en su sitio: ahí es donde escucha su API.
    $router = NetworkDevice::create([
        'name' => 'Router', 'vendor' => DeviceVendor::MIKROTIK, 'role' => DeviceRole::CORE_ROUTER,
        'driver' => 'routeros', 'host' => '10.0.0.3', 'port' => 8728,
        'username' => 'u', 'password' => 'p',
    ]);

    (require base_path('database/migrations/2026_09_03_000003_fix_ubiquiti_management_port.php'))->up();

    expect($malas->map(fn ($d) => $d->fresh()->port)->all())->toBe([443, 443])
        ->and($deliberada->fresh()->port)->toBe(8443)
        ->and($router->fresh()->port)->toBe(8728);
});
