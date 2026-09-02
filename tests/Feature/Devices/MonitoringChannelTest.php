<?php

use App\Enums\DeviceRole;
use App\Enums\DeviceVendor;
use App\Models\DeviceCredential;
use App\Models\DeviceMetricSample;
use App\Models\NetworkDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * El canal por el que un agente de monitoreo recoge qué sondear y devuelve lo
 * leído.
 *
 * Dos de estos tests son fronteras de seguridad, no funcionalidades: que un
 * agente no vea los equipos de otro, y que un rol equivocado no llegue al canal.
 * `targets` reparte credenciales de equipos, así que un fallo ahí entrega las
 * claves del parque entero.
 */
function makeMonitorAgent(): array
{
    return makeProvisioningAgent('monitor');
}

/**
 * GET firmado contra el canal del agente.
 *
 * Con `get()` y no con `getJson()` a propósito: `getJson()` serializa un cuerpo
 * `[]` aunque no le pases datos, así que la firma cubriría dos bytes que un
 * agente real —que hace un GET sin cuerpo— nunca envía, y el middleware la
 * rechazaría. Es justo la clase de desajuste que este test debe detectar.
 */
function getTargets(array $agent)
{
    $path = '/api/agent/monitoring/targets';

    return test()->get($path, array_merge(
        signedAgentHeaders($agent, 'GET', $path),
        ['Accept' => 'application/json'],
    ));
}

function makeMonitoredAntenna(array $overrides = []): NetworkDevice
{
    static $n = 0;
    $n++;

    return NetworkDevice::create(array_merge([
        'name'         => "Antena {$n}",
        'vendor'       => DeviceVendor::UBIQUITI,
        'role'         => DeviceRole::BACKHAUL_AP,
        'driver'       => 'airos',
        'host'         => "10.9.0.{$n}",
        'username'     => 'ubnt',
        'password'     => 'ubnt',
        'is_active'    => true,
        'is_monitored' => true,
    ], $overrides));
}

// ── targets ──────────────────────────────────────────────────────────────────

it('entrega al agente sus equipos con las credenciales resueltas', function () {
    $agent = makeMonitorAgent();
    makeMonitoredAntenna(['agent_id' => $agent['agent']->id]);

    $res = getTargets($agent);

    $res->assertOk();

    expect($res->json('data.targets'))->toHaveCount(1)
        ->and($res->json('data.targets.0.username'))->toBe('ubnt')
        ->and($res->json('data.targets.0.password'))->toBe('ubnt')
        ->and($res->json('data.poll_interval_seconds'))->toBeGreaterThan(0);
});

it('no entrega a un agente los equipos de otro', function () {
    // Frontera de seguridad: sin este filtro, enrolar un agente en una torre
    // daría acceso a las claves de todas las antenas del ISP.
    $mine    = makeMonitorAgent();
    $theirs  = makeMonitorAgent();

    makeMonitoredAntenna(['name' => 'Mía',  'agent_id' => $mine['agent']->id]);
    makeMonitoredAntenna(['name' => 'Suya', 'agent_id' => $theirs['agent']->id]);

    $res = getTargets($mine);

    expect($res->json('data.targets'))->toHaveCount(1)
        ->and($res->json('data.targets.0.name'))->toBe('Mía');
});

it('rechaza a un agente cuyo rol no es el de monitoreo', function () {
    $provisioner = makeProvisioningAgent('provisioner');

    getTargets($provisioner)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'AGENT_ROLE_FORBIDDEN');
});

it('recurre al perfil de credenciales cuando el equipo no tiene las suyas', function () {
    $agent   = makeMonitorAgent();
    $profile = DeviceCredential::create([
        'name' => 'airOS por defecto', 'vendor' => DeviceVendor::UBIQUITI,
        'username' => 'lectura', 'password' => 'solo-lectura',
    ]);

    makeMonitoredAntenna([
        'agent_id' => $agent['agent']->id,
        'username' => '',
        'password' => '',
        'credential_profile_id' => $profile->id,
    ]);

    $res = getTargets($agent);

    expect($res->json('data.targets.0.username'))->toBe('lectura')
        ->and($res->json('data.targets.0.password'))->toBe('solo-lectura');
});

it('las credenciales propias del equipo mandan sobre las del perfil', function () {
    // Los routers del alta automática llevan contraseña generada e individual:
    // un perfil asignado por descuido no puede pisarla.
    $agent   = makeMonitorAgent();
    $profile = DeviceCredential::create([
        'name' => 'Perfil', 'vendor' => DeviceVendor::UBIQUITI,
        'username' => 'perfil', 'password' => 'del-perfil',
    ]);

    makeMonitoredAntenna(['agent_id' => $agent['agent']->id, 'credential_profile_id' => $profile->id]);

    $res = getTargets($agent);

    expect($res->json('data.targets.0.username'))->toBe('ubnt');
});

it('omite los equipos excluidos del sondeo', function () {
    // Una CPE tras NAT no siempre es alcanzable: se excluye sin borrarla del
    // inventario, para que no genere falsos positivos eternos.
    $agent = makeMonitorAgent();
    makeMonitoredAntenna(['agent_id' => $agent['agent']->id, 'is_monitored' => false]);

    $res = getTargets($agent);

    expect($res->json('data.targets'))->toBeEmpty();
});

// ── samples ──────────────────────────────────────────────────────────────────

function postSamples(array $agent, array $samples)
{
    $body = ['samples' => $samples];

    return test()->postJson('/api/agent/monitoring/samples', $body,
        signedAgentHeaders($agent, 'POST', '/api/agent/monitoring/samples', $body));
}

it('guarda un lote de muestras y actualiza el resumen del equipo', function () {
    $agent   = makeMonitorAgent();
    $antenna = makeMonitoredAntenna(['agent_id' => $agent['agent']->id]);

    postSamples($agent, [[
        'device_id'       => $antenna->id,
        'sampled_at'      => now()->getTimestamp(),
        'reachable'       => true,
        'signal_dbm'      => -67,
        'noise_floor_dbm' => -95,
        'ccq_percent'     => 92,
        'uptime_seconds'  => 86400,
    ]])->assertOk()->assertJsonPath('data.stored', 1);

    $sample = DeviceMetricSample::first();

    expect($sample->signal_dbm)->toBe(-67)
        // El SNR se calcula al guardar: no todos los firmwares lo publican, pero
        // casi todos dan señal y ruido.
        ->and($sample->snr_db)->toBe(28)
        ->and($antenna->refresh()->last_signal_dbm)->toBe(-67)
        ->and($antenna->last_ccq_percent)->toBe(92)
        ->and($antenna->connectivity_status)->toBe(NetworkDevice::STATUS_CONNECTED);
});

it('reenviar el mismo lote no duplica filas', function () {
    // El nonce del HMAC protege contra repetir la MISMA petición, no contra un
    // reintento —que genera nonce nuevo—. Si se pierde la respuesta, el agente
    // reenvía: el índice único lo absorbe.
    $agent   = makeMonitorAgent();
    $antenna = makeMonitoredAntenna(['agent_id' => $agent['agent']->id]);
    $at      = now()->getTimestamp();

    $sample = ['device_id' => $antenna->id, 'sampled_at' => $at, 'reachable' => true, 'signal_dbm' => -70];

    postSamples($agent, [$sample])->assertOk();
    $second = postSamples($agent, [$sample])->assertOk();

    expect(DeviceMetricSample::count())->toBe(1)
        ->and($second->json('data.stored'))->toBe(0);
});

it('rechaza muestras con fecha fuera de la ventana admitida', function () {
    // Un agente con el reloj roto podría envenenar meses de serie con lecturas
    // fechadas en 1970, y desde una gráfica eso no se distingue de un dato bueno.
    $agent   = makeMonitorAgent();
    $antenna = makeMonitoredAntenna(['agent_id' => $agent['agent']->id]);

    $res = postSamples($agent, [
        ['device_id' => $antenna->id, 'sampled_at' => now()->subDays(30)->getTimestamp(), 'reachable' => true],
        ['device_id' => $antenna->id, 'sampled_at' => now()->addHours(3)->getTimestamp(),  'reachable' => true],
    ])->assertOk();

    expect(DeviceMetricSample::count())->toBe(0)
        ->and($res->json('data.rejected'))->toHaveCount(2)
        ->and($res->json('data.rejected.0.reason'))->toBe('TIMESTAMP_OUT_OF_RANGE');
});

it('rechaza muestras de equipos que no son de este agente', function () {
    $mine   = makeMonitorAgent();
    $theirs = makeMonitorAgent();
    $ajena  = makeMonitoredAntenna(['agent_id' => $theirs['agent']->id]);

    $res = postSamples($mine, [
        ['device_id' => $ajena->id, 'sampled_at' => now()->getTimestamp(), 'reachable' => false],
    ])->assertOk();

    expect(DeviceMetricSample::count())->toBe(0)
        ->and($res->json('data.rejected.0.reason'))->toBe('NOT_ASSIGNED');
});

it('lleva la cuenta de fallos consecutivos y la reinicia al recuperarse', function () {
    $agent   = makeMonitorAgent();
    $antenna = makeMonitoredAntenna(['agent_id' => $agent['agent']->id]);

    postSamples($agent, [['device_id' => $antenna->id, 'sampled_at' => now()->getTimestamp(), 'reachable' => false]]);
    postSamples($agent, [['device_id' => $antenna->id, 'sampled_at' => now()->addMinute()->getTimestamp(), 'reachable' => false]]);

    expect($antenna->refresh()->consecutive_failures)->toBe(2);

    postSamples($agent, [['device_id' => $antenna->id, 'sampled_at' => now()->addMinutes(2)->getTimestamp(), 'reachable' => true]]);

    expect($antenna->refresh()->consecutive_failures)->toBe(0);
});

it('conserva el payload solo cuando el driver no supo interpretarlo', function () {
    // Es el único caso en que el crudo vale su coste en disco: material para
    // dar soporte a un firmware nuevo.
    $agent   = makeMonitorAgent();
    $antenna = makeMonitoredAntenna(['agent_id' => $agent['agent']->id]);

    postSamples($agent, [[
        'device_id'        => $antenna->id,
        'sampled_at'       => now()->getTimestamp(),
        'reachable'        => true,
        'unparsed_payload' => '{"wireless":{"formato":"desconocido"}}',
    ]])->assertOk();

    expect(DeviceMetricSample::first()->unparsed_payload)->toContain('desconocido');
});
