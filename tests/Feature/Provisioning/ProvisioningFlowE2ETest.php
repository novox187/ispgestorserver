<?php

use App\Enums\ProvisioningStatus;
use App\Models\Audit;
use App\Models\DeviceProvisioningSession;
use App\Models\MikrotikRouter;
use App\Models\RouterVpnProfile;
use App\Services\MikrotikHealthChecker;
use App\Services\Provisioning\ProvisioningAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/**
 * Flujo completo de alta: cable conectado → detección → identificación →
 * VPN en el router → peer en el hosting → verificación en ambos extremos →
 * rotación de credenciales → router registrado y alcanzable.
 *
 * Los dos agentes se simulan reclamando y reportando por HTTP contra los
 * endpoints reales, así que el recorrido pasa por la firma HMAC, el middleware
 * y los controladores. Lo único que se sustituye es el chequeo de salud final,
 * porque es lo único que exige un router de verdad al otro lado.
 */

beforeEach(function () {
    $this->provisioner = makeProvisioningAgent('provisioner');
    $this->vpnHost     = makeProvisioningAgent('vpn_host');

    // El chequeo desde dentro del contenedor: se da por bueno para poder
    // ejercitar el resto sin hardware. Su fallo tiene su propio test.
    $this->mock(MikrotikHealthChecker::class, function (MockInterface $m) {
        $m->shouldReceive('check')->andReturn(['ok' => true, 'error' => null]);
    });
});

/** Reporta la detección tal y como lo haría el agente al ver el MNDP. */
function reportDetection(\Tests\TestCase $test, array $provisioner, array $overrides = []): int
{
    $body = array_merge([
        'detection_method' => 'mndp',
        'mac_address'      => '18:FD:74:AA:BB:CC',
        'identity'         => 'MikroTik',
        'link_interface'   => 'eth1',
        'lan_ip'           => '192.168.88.1',
    ], $overrides);

    $response = $test->postJson(
        '/api/agent/devices/detected',
        $body,
        signedAgentHeaders($provisioner, 'POST', '/api/agent/devices/detected', $body),
    );

    return (int) $response->json('data.session_id');
}

it('completa el alta de punta a punta y deja el router registrado y alcanzable', function () {
    $sessionId = reportDetection($this, $this->provisioner);

    $steps = driveProvisioningFlow($this, $this->provisioner, $this->vpnHost);

    expect($steps)->toBe([
        'identify_device',
        'apply_router_vpn',
        'apply_host_peer',
        'verify_router_vpn',
        'verify_host_peer',
        'harden_router',
    ]);

    $session = DeviceProvisioningSession::find($sessionId);
    expect($session->status)->toBe(ProvisioningStatus::COMPLETED)
        ->and($session->router_id)->not->toBeNull()
        ->and($session->completed_at)->not->toBeNull();

    // El equipo quedó identificado con lo que reportó el agente.
    expect($session->board_name)->toBe('hEX S')
        ->and($session->routeros_version)->toBe('7.15.3')
        ->and($session->serial_number)->toBe('HEX7S0012345');

    $router = MikrotikRouter::find($session->router_id);

    // Lo que de verdad importa: el sistema alcanza al equipo por la VPN, no por
    // la IP de fábrica con la que se le encontró.
    expect($router->host)->toBe('10.77.0.2')
        ->and($router->username)->toBe('ispgestor-api')
        ->and($router->password)->not->toBe('')
        ->and($router->provisioning_source)->toBe('auto')
        ->and($router->provisioned_at)->not->toBeNull()
        ->and($router->serial_number)->toBe('HEX7S0012345')
        ->and($router->connectivity_status)->toBe('connected')
        // Primer router del sistema: la regla de negocio lo hace primary.
        ->and($router->is_primary)->toBeTrue();
});

it('no deja las credenciales de fábrica en el equipo', function () {
    $sessionId = reportDetection($this, $this->provisioner);
    driveProvisioningFlow($this, $this->provisioner, $this->vpnHost);

    $router = MikrotikRouter::find(DeviceProvisioningSession::find($sessionId)->router_id);

    // La contraseña guardada es la generada, no la de fábrica (vacía), y va
    // cifrada en reposo por el cast del modelo.
    expect($router->username)->not->toBe('admin')
        ->and(strlen((string) $router->password))->toBeGreaterThanOrEqual(16);

    $raw = DB::table('network_devices')->where('id', $router->id)->value('password');
    expect($raw)->not->toBe($router->password);
});

it('persiste el perfil VPN con la clave pública que generó el propio router', function () {
    $sessionId = reportDetection($this, $this->provisioner);
    driveProvisioningFlow($this, $this->provisioner, $this->vpnHost);

    $session = DeviceProvisioningSession::find($sessionId);
    $profile = RouterVpnProfile::where('router_id', $session->router_id)->first();

    expect($profile)->not->toBeNull()
        ->and($profile->driver)->toBe('wireguard')
        ->and($profile->status)->toBe(RouterVpnProfile::STATUS_ACTIVE)
        ->and($profile->assigned_ip)->toBe('10.77.0.2')
        ->and($profile->router_public_key)->toBe('cm91dGVyLXB1YmxpYy1rZXktZ2VuZXJhZGEtcG9yLVJPUw==')
        ->and($profile->endpoint_host)->toBe('vpn.ironlink.uk')
        // Cada peer solo puede usar su propia /32: una máscara más ancha
        // permitiría a un router suplantar a otro.
        ->and($profile->allowed_ips)->toBe('10.77.0.2/32');
});

it('nunca transporta claves privadas por la API', function () {
    $sessionId = reportDetection($this, $this->provisioner);
    driveProvisioningFlow($this, $this->provisioner, $this->vpnHost);

    $session = DeviceProvisioningSession::find($sessionId);

    $payloads = App\Models\ProvisioningTask::where('session_id', $sessionId)
        ->get()
        ->map(fn ($t) => json_encode($t->payload))
        ->implode(' ');

    // La del router la genera RouterOS y no sale del equipo; la del servidor
    // vive en el sistema de ficheros del hosting.
    expect($payloads)->not->toContain('private_key')
        ->and($payloads)->not->toContain('PrivateKey');

    $profile = RouterVpnProfile::where('router_id', $session->router_id)->first();
    expect($profile->getAttributes())->not->toHaveKey('router_private_key')
        ->and($profile->getAttributes())->not->toHaveKey('server_private_key');
});

it('no abre una segunda sesión cuando el mismo equipo se reporta de nuevo', function () {
    // MNDP se repite cada 60 s: el mismo router llegará muchas veces mientras
    // dura su alta y no debe encadenar intentos duplicados.
    $first = reportDetection($this, $this->provisioner);

    $this->postJson(
        '/api/agent/devices/detected',
        $body = [
            'detection_method' => 'link_probe',
            'mac_address'      => '18:FD:74:AA:BB:CC',
            'lan_ip'           => '192.168.88.1',
        ],
        signedAgentHeaders($this->provisioner, 'POST', '/api/agent/devices/detected', $body),
    )
        ->assertOk()
        ->assertJsonPath('data.created', false)
        ->assertJsonPath('data.session_id', $first);

    expect(DeviceProvisioningSession::count())->toBe(1);
});

it('re-aprovisiona sobre la misma fila cuando vuelve un equipo ya conocido', function () {
    reportDetection($this, $this->provisioner);
    driveProvisioningFlow($this, $this->provisioner, $this->vpnHost);

    expect(MikrotikRouter::count())->toBe(1);
    $firstRouterId = MikrotikRouter::first()->id;

    // El mismo equipo vuelve al banco: mismo serial, distinta MAC de gestión.
    reportDetection($this, $this->provisioner, ['mac_address' => '18:FD:74:AA:BB:DD']);
    driveProvisioningFlow($this, $this->provisioner, $this->vpnHost);

    expect(MikrotikRouter::count())->toBe(1)
        ->and(MikrotikRouter::first()->id)->toBe($firstRouterId);

    // El perfil anterior queda revocado y su dirección vuelve al pool.
    expect(RouterVpnProfile::where('status', RouterVpnProfile::STATUS_REVOKED)->count())->toBe(1)
        ->and(RouterVpnProfile::where('status', RouterVpnProfile::STATUS_ACTIVE)->count())->toBe(1);
});

it('rechaza un equipo con RouterOS anterior a 7.1 sin tocarlo', function () {
    $sessionId = reportDetection($this, $this->provisioner);

    // Solo se ejecuta la identificación; el rechazo ocurre en el servidor.
    $body    = ['max' => 1];
    $headers = signedAgentHeaders($this->provisioner, 'POST', '/api/agent/tasks/claim', $body);
    $task    = $this->postJson('/api/agent/tasks/claim', $body, $headers)->json('data.tasks.0');

    $uri  = "/api/agent/tasks/{$task['id']}/report";
    $body = [
        'status' => 'succeeded',
        'result' => fakeAgentTaskResult('identify_device', [
            'routeros_version'    => '6.49.10',
            'wireguard_available' => false,
        ]),
    ];
    $this->postJson($uri, $body, signedAgentHeaders($this->provisioner, 'POST', $uri, $body))
        ->assertOk();

    $session = DeviceProvisioningSession::find($sessionId);

    expect($session->status)->toBe(ProvisioningStatus::FAILED)
        ->and($session->error_code)->toBe('ROUTEROS_VERSION_UNSUPPORTED');

    // Lo esencial: no se le escribió nada al equipo ni quedó nada que revertir.
    expect($session->compensations ?? [])->toBe([])
        ->and(MikrotikRouter::count())->toBe(0)
        ->and(App\Models\ProvisioningTask::where('session_id', $sessionId)->count())->toBe(1);

    expect(Audit::where('table_name', ProvisioningAuditor::TABLE_SESSIONS)
        ->where('operation', ProvisioningAuditor::REJECTED_INCOMPATIBLE)
        ->exists())->toBeTrue();
});

it('aborta antes de tocar el router si el equipo no tiene salida a internet', function () {
    $sessionId = reportDetection($this, $this->provisioner);

    $body    = ['max' => 1];
    $headers = signedAgentHeaders($this->provisioner, 'POST', '/api/agent/tasks/claim', $body);
    $task    = $this->postJson('/api/agent/tasks/claim', $body, $headers)->json('data.tasks.0');

    $uri  = "/api/agent/tasks/{$task['id']}/report";
    $body = [
        'status' => 'succeeded',
        'result' => fakeAgentTaskResult('identify_device', ['wan_reachable' => false]),
    ];
    $this->postJson($uri, $body, signedAgentHeaders($this->provisioner, 'POST', $uri, $body))
        ->assertOk();

    $session = DeviceProvisioningSession::find($sessionId);

    // Sin WAN el router jamás alcanzaría el endpoint: se detecta aquí y no tres
    // pasos más adelante con un handshake que nunca llega.
    expect($session->status)->toBe(ProvisioningStatus::FAILED)
        ->and($session->error_code)->toBe('ROUTER_NO_WAN')
        ->and($session->compensations ?? [])->toBe([]);
});

it('no inicia el alta si no hay ningún agente de VPN disponible', function () {
    $this->vpnHost['agent']->forceFill(['is_active' => false])->save();

    $sessionId = reportDetection($this, $this->provisioner);
    driveProvisioningFlow($this, $this->provisioner, $this->vpnHost);

    $session = DeviceProvisioningSession::find($sessionId);

    // Empezar sabiendo que el otro extremo no está solo garantizaría un
    // rollback, así que se para antes de escribir nada en el equipo.
    expect($session->status)->toBe(ProvisioningStatus::FAILED)
        ->and($session->error_code)->toBe('VPN_HOST_UNAVAILABLE')
        ->and($session->compensations ?? [])->toBe([]);
});

it('reparte direcciones distintas a dos altas simultáneas', function () {
    $first  = reportDetection($this, $this->provisioner, ['mac_address' => '18:FD:74:00:00:01']);
    $second = reportDetection($this, $this->provisioner, ['mac_address' => '18:FD:74:00:00:02']);

    // Se avanzan las dos sesiones intercaladas, que es el caso en el que un
    // asignador ingenuo entregaría la misma IP a ambas.
    driveProvisioningFlow($this, $this->provisioner, $this->vpnHost);

    $ips = DeviceProvisioningSession::whereIn('id', [$first, $second])
        ->pluck('vpn_assigned_ip')
        ->filter()
        ->values();

    expect($ips)->toHaveCount(2)
        ->and($ips->unique())->toHaveCount(2);
});

it('espera aprobación cuando el alta automática está desactivada', function () {
    App\Models\AutomationSetting::create([
        'key'           => App\Services\Provisioning\ProvisioningSettings::SETTING_KEY,
        'name'          => 'Alta automática',
        'job_class'     => App\Jobs\ExpireStaleProvisioningTasks::class,
        'queue'         => 'provisioning',
        'enabled'       => true,
        'schedule_type' => 'every_five_minutes',
        'params'        => ['auto_approve' => false],
    ]);

    $sessionId = reportDetection($this, $this->provisioner);
    $steps     = driveProvisioningFlow($this, $this->provisioner, $this->vpnHost);

    expect($steps)->toBe(['identify_device']);

    $session = DeviceProvisioningSession::find($sessionId);
    expect($session->status)->toBe(ProvisioningStatus::AWAITING_APPROVAL)
        ->and($session->compensations ?? [])->toBe([]);

    // El administrador aprueba y el flujo continúa donde se quedó.
    $this->actingAs(makeSuperAdminEmployee(), 'sanctum')
        ->postJson("/api/admin/provisioning/sessions/{$sessionId}/approve")
        ->assertOk();

    driveProvisioningFlow($this, $this->provisioner, $this->vpnHost);

    expect(DeviceProvisioningSession::find($sessionId)->status)
        ->toBe(ProvisioningStatus::COMPLETED);
});
