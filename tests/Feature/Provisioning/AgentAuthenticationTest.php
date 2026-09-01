<?php

use App\Models\Audit;
use App\Models\ProvisioningAgent;
use App\Services\Provisioning\AgentSignature;
use App\Services\Provisioning\ProvisioningAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * El canal de agentes es la frontera entre la aplicación y la infraestructura
 * de red: quien la cruza puede pedir que se toque la configuración de un router
 * o que se cree un peer en el hosting. Estos tests ejercitan cada rechazo del
 * middleware, no solo el camino feliz.
 */

const CLAIM_URI = '/api/agent/tasks/claim';

it('acepta una petición correctamente firmada', function () {
    $enrolled = makeProvisioningAgent('provisioner');
    $body     = ['max' => 1];

    $this->postJson(CLAIM_URI, $body, signedAgentHeaders($enrolled, 'POST', CLAIM_URI, $body))
        ->assertOk()
        ->assertJsonPath('data.tasks', []);
});

it('rechaza una petición sin cabeceras de firma', function () {
    $this->postJson(CLAIM_URI, ['max' => 1])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'AGENT_MISSING_HEADERS');
});

it('rechaza un token desconocido', function () {
    $enrolled = makeProvisioningAgent('provisioner');
    $body     = ['max' => 1];

    $headers = signedAgentHeaders($enrolled, 'POST', CLAIM_URI, $body, [
        'token' => str_repeat('a', 64),
    ]);

    $this->postJson(CLAIM_URI, $body, $headers)
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'AGENT_UNKNOWN_TOKEN');
});

it('rechaza a un agente revocado de inmediato', function () {
    $enrolled = makeProvisioningAgent('provisioner');
    $enrolled['agent']->forceFill(['is_active' => false])->save();

    $body    = ['max' => 1];
    $headers = signedAgentHeaders($enrolled, 'POST', CLAIM_URI, $body);

    $this->postJson(CLAIM_URI, $body, $headers)
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'AGENT_REVOKED');
});

it('rechaza una firma alterada', function () {
    $enrolled = makeProvisioningAgent('provisioner');
    $body     = ['max' => 1];

    $headers = signedAgentHeaders($enrolled, 'POST', CLAIM_URI, $body, [
        'secret' => 'un-secreto-que-no-es-el-suyo',
    ]);

    $this->postJson(CLAIM_URI, $body, $headers)
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'AGENT_BAD_SIGNATURE');
});

it('rechaza un cuerpo manipulado en tránsito', function () {
    $enrolled = makeProvisioningAgent('provisioner');

    // Se firma un cuerpo y se envía otro: es exactamente lo que haría un
    // intermediario que quisiera cambiar la instrucción.
    $headers = signedAgentHeaders($enrolled, 'POST', CLAIM_URI, ['max' => 1]);

    $this->postJson(CLAIM_URI, ['max' => 10], $headers)
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'AGENT_BAD_SIGNATURE');
});

it('rechaza una marca de tiempo fuera de la ventana admitida', function () {
    $enrolled = makeProvisioningAgent('provisioner');
    $body     = ['max' => 1];

    $headers = signedAgentHeaders($enrolled, 'POST', CLAIM_URI, $body, [
        'timestamp' => (string) now()->subSeconds(AgentSignature::MAX_SKEW_SECONDS + 60)->getTimestamp(),
    ]);

    $this->postJson(CLAIM_URI, $body, $headers)
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'AGENT_CLOCK_SKEW');
});

it('rechaza la repetición de una petición ya procesada', function () {
    $enrolled = makeProvisioningAgent('provisioner');
    $body     = ['max' => 1];
    $headers  = signedAgentHeaders($enrolled, 'POST', CLAIM_URI, $body);

    // La captura de una petición legítima y su reenvío íntegro es el ataque que
    // la firma por sí sola no detiene: los bytes son válidos.
    $this->postJson(CLAIM_URI, $body, $headers)->assertOk();

    $this->postJson(CLAIM_URI, $body, $headers)
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'AGENT_REPLAY');
});

it('no consume el nonce cuando la firma es inválida', function () {
    $enrolled = makeProvisioningAgent('provisioner');
    $body     = ['max' => 1];
    $nonce    = (string) Illuminate\Support\Str::uuid();

    // Un tercero con el token pero sin el secreto no debe poder quemar los
    // nonces de un agente legítimo.
    $this->postJson(CLAIM_URI, $body, signedAgentHeaders($enrolled, 'POST', CLAIM_URI, $body, [
        'nonce'  => $nonce,
        'secret' => 'secreto-incorrecto',
    ]))->assertStatus(401)->assertJsonPath('error.code', 'AGENT_BAD_SIGNATURE');

    // El agente real usa ese mismo nonce y debe pasar sin problema.
    $this->postJson(CLAIM_URI, $body, signedAgentHeaders($enrolled, 'POST', CLAIM_URI, $body, [
        'nonce' => $nonce,
    ]))->assertOk();
});

it('audita cada intento de autenticación rechazado', function () {
    $enrolled = makeProvisioningAgent('provisioner');
    $body     = ['max' => 1];

    $this->postJson(CLAIM_URI, $body, signedAgentHeaders($enrolled, 'POST', CLAIM_URI, $body, [
        'secret' => 'incorrecto',
    ]))->assertStatus(401);

    $audit = Audit::where('table_name', ProvisioningAuditor::TABLE_AGENTS)
        ->where('operation', ProvisioningAuditor::AGENT_AUTH_FAILED)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->new_values['reason'])->toBe('AGENT_BAD_SIGNATURE')
        ->and($audit->record_id)->toBe((string) $enrolled['agent']->id);
});

it('refresca el último contacto del agente en cada petición válida', function () {
    $enrolled = makeProvisioningAgent('provisioner');
    $enrolled['agent']->forceFill(['last_seen_at' => now()->subHour()])->saveQuietly();

    $body = ['max' => 1];
    $this->postJson(CLAIM_URI, $body, signedAgentHeaders($enrolled, 'POST', CLAIM_URI, $body))
        ->assertOk();

    expect($enrolled['agent']->fresh()->last_seen_at->diffInSeconds(now()))->toBeLessThan(5);
});

it('impide que un agente reporte una tarea que no es suya', function () {
    $mine     = makeProvisioningAgent('provisioner');
    $other    = makeProvisioningAgent('provisioner', [], ['name' => 'Otro agente']);

    $session = App\Models\DeviceProvisioningSession::create([
        'agent_id'         => $other['agent']->id,
        'status'           => App\Enums\ProvisioningStatus::IDENTIFYING,
        'detection_method' => 'mndp',
        'mac_address'      => '18:FD:74:00:00:01',
    ]);

    $task = App\Models\ProvisioningTask::create([
        'session_id' => $session->id,
        'agent_id'   => $other['agent']->id,
        'type'       => App\Enums\ProvisioningTaskType::IDENTIFY_DEVICE,
        'payload'    => [],
        'status'     => App\Enums\ProvisioningTaskStatus::CLAIMED,
    ]);

    $uri  = "/api/agent/tasks/{$task->id}/report";
    $body = ['status' => 'succeeded', 'result' => []];

    $this->postJson($uri, $body, signedAgentHeaders($mine, 'POST', $uri, $body))
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'TASK_NOT_FOUND');
});

it('no entrega a un agente tareas ajenas a su rol', function () {
    // Un `vpn_host` nunca debe recibir un payload con credenciales de router,
    // ni aunque una tarea acabase apuntándole por error.
    $vpnHost = makeProvisioningAgent('vpn_host');

    $session = App\Models\DeviceProvisioningSession::create([
        'agent_id'         => $vpnHost['agent']->id,
        'status'           => App\Enums\ProvisioningStatus::IDENTIFYING,
        'detection_method' => 'mndp',
        'mac_address'      => '18:FD:74:00:00:02',
    ]);

    App\Models\ProvisioningTask::create([
        'session_id' => $session->id,
        'agent_id'   => $vpnHost['agent']->id,
        'type'       => App\Enums\ProvisioningTaskType::APPLY_ROUTER_VPN,
        'payload'    => ['connection' => ['password' => 'secreto-del-router']],
        'status'     => App\Enums\ProvisioningTaskStatus::PENDING,
    ]);

    $body = ['max' => 1];
    $this->postJson(CLAIM_URI, $body, signedAgentHeaders($vpnHost, 'POST', CLAIM_URI, $body))
        ->assertOk()
        ->assertJsonPath('data.tasks', []);
});

// ── Enrolamiento ─────────────────────────────────────────────────────────────

it('canjea un token de enrolamiento válido por credenciales', function () {
    $agent = ProvisioningAgent::create([
        'name' => 'Agente nuevo', 'role' => 'provisioner', 'is_active' => true,
    ]);
    $token = $agent->issueEnrollmentToken();

    $response = $this->postJson('/api/agent/enroll', [
        'enrollment_token' => $token,
        'hostname'         => 'bench-oficina',
        'agent_version'    => '1.0.0',
    ])->assertStatus(201);

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty()
        ->and($response->json('data.secret'))->toBeString()->not->toBeEmpty()
        ->and($agent->fresh()->enrolled_at)->not->toBeNull()
        ->and($agent->fresh()->enrollment_token_hash)->toBeNull();
});

it('no permite canjear dos veces el mismo token de enrolamiento', function () {
    $agent = ProvisioningAgent::create([
        'name' => 'Agente nuevo', 'role' => 'provisioner', 'is_active' => true,
    ]);
    $token = $agent->issueEnrollmentToken();

    $this->postJson('/api/agent/enroll', ['enrollment_token' => $token])->assertStatus(201);

    $this->postJson('/api/agent/enroll', ['enrollment_token' => $token])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'AGENT_ENROLLMENT_INVALID');
});

it('rechaza un token de enrolamiento caducado', function () {
    $agent = ProvisioningAgent::create([
        'name' => 'Agente nuevo', 'role' => 'provisioner', 'is_active' => true,
    ]);
    $token = $agent->issueEnrollmentToken();

    $agent->forceFill(['enrollment_expires_at' => now()->subMinute()])->save();

    $this->postJson('/api/agent/enroll', ['enrollment_token' => $token])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'AGENT_ENROLLMENT_INVALID');
});

it('rechaza el enrolamiento de un agente de VPN que no publica los datos del túnel', function () {
    // Un vpn_host sin clave pública ni endpoint es inservible: la saga no
    // podría decirle al router a dónde marcar. Mejor rechazarlo aquí que a
    // mitad de un alta.
    $agent = ProvisioningAgent::create([
        'name' => 'VPN incompleto', 'role' => 'vpn_host', 'is_active' => true,
    ]);
    $token = $agent->issueEnrollmentToken();

    $this->postJson('/api/agent/enroll', [
        'enrollment_token' => $token,
        'capabilities'     => ['interface' => 'wg0'],
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'AGENT_CAPABILITIES_INCOMPLETE');

    expect($agent->fresh()->is_active)->toBeFalse();
});
