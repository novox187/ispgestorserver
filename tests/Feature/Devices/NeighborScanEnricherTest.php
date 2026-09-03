<?php

use App\Enums\DeviceVendor;
use App\Models\NetworkDevice;
use App\Models\NetworkScan;
use App\Models\NetworkScanFinding;
use App\Services\Devices\DeviceCapability;
use App\Services\Devices\DeviceDriver;
use App\Services\Devices\DeviceDriverRegistry;
use App\Services\Devices\Dto\DeviceTelemetry;
use App\Services\Devices\Dto\NeighborLink;
use App\Services\Devices\Dto\ProbeResult;
use App\Services\Devices\NeighborScanEnricher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Fusión de las dos fuentes de descubrimiento.
 *
 * El barrido UDP solo lo contestan los equipos airOS; los MikroTik hablan MNDP,
 * que no cruza un enlace enrutado. La otra mitad del parque sale de la tabla de
 * vecinos del router. Medido en producción: 25 equipos por una vía, 146 por la
 * otra, y solo 16 en común — ninguna sustituye a la otra.
 */
function registrarDriverConVecinos(array $vecinos): void
{
    $driver = new class($vecinos) implements DeviceDriver {
        public function __construct(private array $vecinos) {}
        public function vendor(): string { return DeviceVendor::MIKROTIK->value; }
        public function name(): string { return 'routeros'; }
        public function supports(DeviceCapability $c): bool { return $c === DeviceCapability::NEIGHBORS; }
        public function probe(NetworkDevice $d, ?int $t = null): ProbeResult { return ProbeResult::down('n/a'); }
        public function telemetry(NetworkDevice $d, ?int $t = null): DeviceTelemetry { return DeviceTelemetry::unreachable('n/a'); }
        public function neighbors(NetworkDevice $d, ?int $t = null): array { return $this->vecinos; }
        public function normalize(array $raw): DeviceTelemetry { return DeviceTelemetry::unparsed('prueba'); }
    };

    app(DeviceDriverRegistry::class)->register($driver);
}

function routerConsultable(): NetworkDevice
{
    return NetworkDevice::create([
        'name' => 'Router Principal', 'vendor' => 'mikrotik', 'role' => 'core_router',
        'driver' => 'routeros', 'host' => '10.0.0.3', 'is_active' => true,
    ]);
}

function barridoDe(string $cidr = '10.10.10.0/24'): NetworkScan
{
    return NetworkScan::create([
        'agent_id' => makeProvisioningAgent('monitor')['agent']->id,
        'cidr'     => $cidr,
        'status'   => NetworkScan::STATUS_COMPLETED,
    ]);
}

it('añade los MikroTik que el barrido UDP no puede ver', function () {
    $router = routerConsultable();
    registrarDriverConVecinos([
        new NeighborLink(
            remoteMac: '00:0C:42:DC:FA:72',
            remoteIdentity: 'OMNITIK LA PAZ',
            remoteIp: '10.10.10.252',
            localInterface: 'ether5',
            platform: 'MikroTik',
        ),
    ]);

    $scan = barridoDe();
    $anadidos = app(NeighborScanEnricher::class)->enrich($scan);

    $f = NetworkScanFinding::where('ip_address', '10.10.10.252')->first();

    expect($anadidos)->toBe(1)
        ->and($f->source)->toBe(NetworkScanFinding::SOURCE_NEIGHBOR)
        ->and($f->vendor)->toBe('mikrotik')
        ->and($f->hostname)->toBe('OMNITIK LA PAZ')
        ->and($f->model)->toBe('MikroTik')
        // Lo que después permite dibujar el enlace sin preguntar nada.
        ->and($f->discovered_via_device_id)->toBe($router->id)
        ->and($f->remote_interface)->toBe('ether5');
});

it('descarta lo que cae fuera del rango que se pidió barrer', function () {
    // La tabla del router de borde alcanza todo el ISP: en producción devolvió
    // seis redes y 146 equipos, la mayoría CPE de abonado de otras torres.
    // Volcarlos por pedir la red de gestión enterraría lo que se buscaba.
    routerConsultable();
    registrarDriverConVecinos([
        new NeighborLink(remoteMac: 'AA:BB:CC:00:00:01', remoteIp: '10.10.10.50'),
        new NeighborLink(remoteMac: 'AA:BB:CC:00:00:02', remoteIp: '192.168.14.70'),
        new NeighborLink(remoteMac: 'AA:BB:CC:00:00:03', remoteIp: '192.168.9.111'),
    ]);

    app(NeighborScanEnricher::class)->enrich(barridoDe('10.10.10.0/24'));

    expect(NetworkScanFinding::count())->toBe(1)
        ->and(NetworkScanFinding::first()->ip_address)->toBe('10.10.10.50');
});

it('marca como «both» lo que ven las dos fuentes y completa lo que falte', function () {
    $router = routerConsultable();
    $scan   = barridoDe();

    // Lo que dejó el barrido UDP: sabe el firmware, no sabe de quién es vecino.
    NetworkScanFinding::create([
        'scan_id' => $scan->id, 'source' => NetworkScanFinding::SOURCE_SWEEP,
        'ip_address' => '10.10.10.250', 'mac_address' => 'FC:EC:DA:6C:90:51',
        'firmware' => 'WA.ar934x.v8.7.22', 'hostname' => 'STATION DORADO',
        'created_at' => now(),
    ]);

    registrarDriverConVecinos([
        new NeighborLink(
            remoteMac: 'FC:EC:DA:6C:90:51',
            remoteIdentity: 'OTRO NOMBRE',
            remoteIp: '10.10.10.250',
            localInterface: 'ether2',
            platform: 'NanoBeam 5AC',
        ),
    ]);

    app(NeighborScanEnricher::class)->enrich($scan);

    $f = NetworkScanFinding::where('ip_address', '10.10.10.250')->first();

    expect(NetworkScanFinding::count())->toBe(1)
        ->and($f->source)->toBe(NetworkScanFinding::SOURCE_BOTH)
        // Se rellena lo que faltaba…
        ->and($f->model)->toBe('NanoBeam 5AC')
        ->and($f->discovered_via_device_id)->toBe($router->id)
        // …pero no se pisa lo que el propio equipo dijo de sí mismo.
        ->and($f->hostname)->toBe('STATION DORADO')
        ->and($f->firmware)->toBe('WA.ar934x.v8.7.22');
});

it('marca los que ya están en el inventario en vez de duplicarlos', function () {
    routerConsultable();

    $ya = NetworkDevice::create([
        'name' => 'AP Dorado', 'vendor' => 'ubiquiti', 'role' => 'sector_ap',
        'driver' => 'airos', 'host' => '10.10.10.249', 'mac_address' => 'E0:63:DA:48:7E:BF',
        'is_active' => true,
    ]);

    registrarDriverConVecinos([
        new NeighborLink(remoteMac: 'E0:63:DA:48:7E:BF', remoteIp: '10.10.10.249'),
    ]);

    app(NeighborScanEnricher::class)->enrich(barridoDe());

    expect(NetworkScanFinding::first()->matched_device_id)->toBe($ya->id);
});

it('no consulta equipos que sondea un agente', function () {
    // Sus credenciales viajan al agente; el servidor no tiene por qué
    // alcanzarlos. Mismo criterio que DiscoverTopologyLinksJob.
    $agente = makeProvisioningAgent('monitor')['agent'];

    NetworkDevice::create([
        'name' => 'Router remoto', 'vendor' => 'mikrotik', 'role' => 'edge_router',
        'driver' => 'routeros', 'host' => '10.10.10.9', 'is_active' => true,
        'agent_id' => $agente->id,
    ]);

    registrarDriverConVecinos([
        new NeighborLink(remoteMac: 'AA:BB:CC:00:00:09', remoteIp: '10.10.10.9'),
    ]);

    expect(app(NeighborScanEnricher::class)->enrich(barridoDe()))->toBe(0)
        ->and(NetworkScanFinding::count())->toBe(0);
});

it('un router que no responde no impide guardar lo del resto', function () {
    routerConsultable();

    $driver = new class implements DeviceDriver {
        public function vendor(): string { return DeviceVendor::MIKROTIK->value; }
        public function name(): string { return 'routeros'; }
        public function supports(DeviceCapability $c): bool { return true; }
        public function probe(NetworkDevice $d, ?int $t = null): ProbeResult { return ProbeResult::down('n/a'); }
        public function telemetry(NetworkDevice $d, ?int $t = null): DeviceTelemetry { return DeviceTelemetry::unreachable('n/a'); }
        public function neighbors(NetworkDevice $d, ?int $t = null): array { throw new RuntimeException('timeout'); }
        public function normalize(array $raw): DeviceTelemetry { return DeviceTelemetry::unparsed('prueba'); }
    };
    app(DeviceDriverRegistry::class)->register($driver);

    expect(app(NeighborScanEnricher::class)->enrich(barridoDe()))->toBe(0);
});

it('un CIDR ilegible no revienta el enriquecido', function () {
    routerConsultable();
    registrarDriverConVecinos([new NeighborLink(remoteMac: 'AA:BB:CC:00:00:01', remoteIp: '10.10.10.5')]);

    $scan = barridoDe();
    $scan->update(['cidr' => 'no-es-un-cidr']);

    expect(app(NeighborScanEnricher::class)->enrich($scan))->toBe(0);
});
