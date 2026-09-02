<?php

use App\Enums\DeviceRole;
use App\Enums\DeviceVendor;
use App\Models\DeviceMetricSample;
use App\Models\NetworkDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * El camino completo de una antena: el agente trae el JSON crudo de `status.cgi`
 * y el servidor lo normaliza.
 *
 * Esta división es la decisión de diseño de la fase. El agente no interpreta
 * nada, así que dar soporte a un firmware nuevo es un despliegue del servidor y
 * no ir a la oficina del cliente a actualizar un demonio de Python en cada
 * máquina donde corra.
 */
function airosRaw(string $fixture): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/airos/{$fixture}.json")), true);
}

function monitorAgentWithAntenna(): array
{
    $agent = makeProvisioningAgent('monitor');

    $antenna = NetworkDevice::create([
        'name'     => 'Enlace Torre Norte',
        'vendor'   => DeviceVendor::UBIQUITI,
        'role'     => DeviceRole::BACKHAUL_STATION,
        'driver'   => 'airos',
        'host'     => '10.9.0.5',
        'username' => 'ubnt',
        'password' => 'ubnt',
        'agent_id' => $agent['agent']->id,
    ]);

    return [$agent, $antenna];
}

function pushRaw(array $agent, int $deviceId, array $raw, bool $reachable = true)
{
    $body = ['samples' => [[
        'device_id'  => $deviceId,
        'sampled_at' => now()->getTimestamp(),
        'reachable'  => $reachable,
        'raw'        => $raw,
    ]]];

    return test()->postJson('/api/agent/monitoring/samples', $body,
        signedAgentHeaders($agent, 'POST', '/api/agent/monitoring/samples', $body));
}

it('normaliza en el servidor el JSON crudo que envía el agente', function () {
    [$agent, $antenna] = monitorAgentWithAntenna();

    pushRaw($agent, $antenna->id, airosRaw('xw-6.3.11-station'))->assertOk();

    $sample = DeviceMetricSample::first();

    expect($sample->signal_dbm)->toBe(-67)
        ->and($sample->noise_floor_dbm)->toBe(-95)
        ->and($sample->snr_db)->toBe(28)
        // 950 sobre 1000 en airOS 6.x → 95%.
        ->and($sample->ccq_percent)->toBe(95)
        ->and($sample->frequency_mhz)->toBe(5805)
        ->and($sample->uptime_seconds)->toBe(1857600);
});

it('actualiza el resumen del equipo para el listado y el mapa', function () {
    [$agent, $antenna] = monitorAgentWithAntenna();

    pushRaw($agent, $antenna->id, airosRaw('xc-8.7.4-ap'))->assertOk();

    expect($antenna->refresh()->last_signal_dbm)->toBe(-58)
        ->and($antenna->last_ccq_percent)->toBe(88)
        ->and($antenna->connectivity_status)->toBe(NetworkDevice::STATUS_CONNECTED)
        ->and($antenna->last_telemetry_at)->not->toBeNull();
});

it('guarda el payload y NO marca caído un firmware que no sabe leer', function () {
    // La regla que decide si el monitoreo sirve: el cliente actualiza una tanda
    // de antenas, el parser no las entiende, y no pueden salir treinta alertas
    // de enlace caído sobre enlaces que funcionan.
    [$agent, $antenna] = monitorAgentWithAntenna();

    pushRaw($agent, $antenna->id, airosRaw('firmware-desconocido'))->assertOk();

    $sample = DeviceMetricSample::first();

    expect($sample->unparsed_payload)->toContain('airOS-del-futuro')
        ->and($sample->signal_dbm)->toBeNull()
        ->and($antenna->refresh()->connectivity_status)->toBe(NetworkDevice::STATUS_CONNECTED)
        ->and($antenna->consecutive_failures)->toBe(0);
});

it('una antena inalcanzable cuenta como fallo, no como dato', function () {
    [$agent, $antenna] = monitorAgentWithAntenna();

    $body = ['samples' => [[
        'device_id'  => $antenna->id,
        'sampled_at' => now()->getTimestamp(),
        'reachable'  => false,
        'error'      => 'AIROS_UNREACHABLE: sin ruta al host',
    ]]];

    test()->postJson('/api/agent/monitoring/samples', $body,
        signedAgentHeaders($agent, 'POST', '/api/agent/monitoring/samples', $body))->assertOk();

    expect($antenna->refresh()->consecutive_failures)->toBe(1)
        ->and(DeviceMetricSample::first()->signal_dbm)->toBeNull();
});

it('no guarda el crudo cuando sí supo interpretarlo', function () {
    // El payload solo vale su coste en disco cuando hace falta para depurar.
    [$agent, $antenna] = monitorAgentWithAntenna();

    pushRaw($agent, $antenna->id, airosRaw('xw-6.3.11-station'))->assertOk();

    expect(DeviceMetricSample::first()->unparsed_payload)->toBeNull();
});

it('cuenta las estaciones asociadas de un sector', function () {
    [$agent, $antenna] = monitorAgentWithAntenna();

    pushRaw($agent, $antenna->id, airosRaw('xc-8.7.4-ap'))->assertOk();

    expect(DeviceMetricSample::first()->station_count)->toBe(3);
});
