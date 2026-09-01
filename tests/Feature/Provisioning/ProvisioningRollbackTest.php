<?php

use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningTaskStatus;
use App\Jobs\ExpireStaleProvisioningTasks;
use App\Models\Audit;
use App\Models\DeviceProvisioningSession;
use App\Models\MikrotikRouter;
use App\Models\ProvisioningTask;
use App\Models\RouterVpnProfile;
use App\Services\MikrotikHealthChecker;
use App\Services\Provisioning\ProvisioningAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/**
 * Un alta toca dos máquinas ajenas a la base de datos: un router y el sistema
 * operativo del hosting. Ahí no hay transacción que revierta nada, así que la
 * saga tiene que deshacer explícitamente lo hecho.
 *
 * Lo que estos tests protegen es justo eso: que un fallo a mitad de camino no
 * deje una interfaz WireGuard huérfana en el equipo, un peer fantasma en el
 * hosting, una dirección retenida del pool ni una fila de router a medias.
 */

beforeEach(function () {
    $this->provisioner = makeProvisioningAgent('provisioner');
    $this->vpnHost     = makeProvisioningAgent('vpn_host');
});

function startSession(\Tests\TestCase $test, array $provisioner, array $overrides = []): int
{
    $body = array_merge([
        'detection_method' => 'mndp',
        'mac_address'      => '18:FD:74:AA:BB:CC',
        'link_interface'   => 'eth1',
        'lan_ip'           => '192.168.88.1',
    ], $overrides);

    return (int) $test->postJson(
        '/api/agent/devices/detected',
        $body,
        signedAgentHeaders($provisioner, 'POST', '/api/agent/devices/detected', $body),
    )->json('data.session_id');
}

it('revierte el router cuando falla el registro del peer en el hosting', function () {
    $sessionId = startSession($this, $this->provisioner);

    $steps = driveProvisioningFlow($this, $this->provisioner, $this->vpnHost, failAt: [
        'apply_host_peer' => ['code' => 'WG_SET_FAILED', 'message' => 'wg set devolvió código 1.'],
    ]);

    // La compensación del router se ejecuta; la del hosting también, porque se
    // apiló antes de mandar la tarea que falló y no hay forma de saber si el
    // agente llegó a aplicar algo antes de morir.
    expect($steps)->toBe([
        'identify_device',
        'apply_router_vpn',
        'apply_host_peer',
        'rollback_host_peer',
        'rollback_router_vpn',
    ]);

    $session = DeviceProvisioningSession::find($sessionId);

    expect($session->status)->toBe(ProvisioningStatus::ROLLED_BACK)
        ->and($session->error_code)->toBe('WG_SET_FAILED')
        // La pila queda vacía: no hay nada pendiente de limpiar a mano.
        ->and($session->compensations ?? [])->toBe([]);

    // Y sobre todo: no quedó ningún router registrado a medias.
    expect(MikrotikRouter::count())->toBe(0);
});

it('devuelve la dirección al pool tras una reversión', function () {
    $first = startSession($this, $this->provisioner, ['mac_address' => '18:FD:74:00:00:01']);

    driveProvisioningFlow($this, $this->provisioner, $this->vpnHost, failAt: [
        'apply_host_peer' => ['code' => 'WG_SET_FAILED', 'message' => 'fallo'],
    ]);

    expect(DeviceProvisioningSession::find($first)->status)->toBe(ProvisioningStatus::ROLLED_BACK);

    // El siguiente alta debe poder reutilizar la dirección: retenerla tras cada
    // intento fallido agotaría la subred a base de fracasos.
    $this->mock(MikrotikHealthChecker::class, function (MockInterface $m) {
        $m->shouldReceive('check')->andReturn(['ok' => true, 'error' => null]);
    });

    $second = startSession($this, $this->provisioner, ['mac_address' => '18:FD:74:00:00:02']);
    driveProvisioningFlow($this, $this->provisioner, $this->vpnHost);

    expect(DeviceProvisioningSession::find($second)->status)->toBe(ProvisioningStatus::COMPLETED)
        ->and(DeviceProvisioningSession::find($second)->vpn_assigned_ip)->toBe('10.77.0.2');
});

it('revierte ambos extremos cuando la verificación no confirma el túnel', function () {
    $sessionId = startSession($this, $this->provisioner);

    $steps = driveProvisioningFlow($this, $this->provisioner, $this->vpnHost, failAt: [
        'verify_router_vpn' => ['code' => 'NO_HANDSHAKE', 'message' => 'Sin handshake tras 120 s.'],
    ]);

    expect($steps)->toContain('rollback_host_peer')
        ->and($steps)->toContain('rollback_router_vpn');

    $session = DeviceProvisioningSession::find($sessionId);

    expect($session->status)->toBe(ProvisioningStatus::ROLLED_BACK)
        ->and($session->error_code)->toBe('NO_HANDSHAKE')
        ->and(MikrotikRouter::count())->toBe(0);
});

it('revierte y no deja fila cuando el contenedor no alcanza al equipo por el túnel', function () {
    // Los dos extremos dicen tener handshake pero la aplicación no llega. Es el
    // fallo de despliegue típico: el enrutado entre el bridge de Docker y la
    // interfaz wg del host. El alta no puede darse por buena.
    $this->mock(MikrotikHealthChecker::class, function (MockInterface $m) {
        $m->shouldReceive('check')->andReturn(['ok' => false, 'error' => 'Connection timed out']);
    });

    $sessionId = startSession($this, $this->provisioner);
    driveProvisioningFlow($this, $this->provisioner, $this->vpnHost);

    $session = DeviceProvisioningSession::find($sessionId);

    expect($session->status)->toBe(ProvisioningStatus::ROLLED_BACK)
        ->and($session->error_code)->toBe('CONTAINER_CANNOT_REACH_ROUTER')
        // El mensaje apunta a la causa real para no mandar a nadie a depurar
        // la VPN, que es justo lo que sí funciona.
        ->and($session->error_message)->toContain('FORWARD')
        ->and($session->router_id)->toBeNull();

    // La fila que se llegó a crear se borra: dejarla haría que el sistema
    // operase contra un equipo inalcanzable, y siendo la primera, además
    // bloquearía con 423 todas las rutas con `primary_router`.
    expect(MikrotikRouter::count())->toBe(0)
        ->and(RouterVpnProfile::where('status', RouterVpnProfile::STATUS_ACTIVE)->count())->toBe(0);
});

it('sigue revirtiendo el resto aunque una compensación falle', function () {
    $sessionId = startSession($this, $this->provisioner);

    $steps = driveProvisioningFlow($this, $this->provisioner, $this->vpnHost, failAt: [
        'verify_host_peer'   => ['code' => 'NO_HANDSHAKE', 'message' => 'sin handshake'],
        // El agente del hosting no consigue quitar el peer.
        'rollback_host_peer' => ['code' => 'WG_REMOVE_FAILED', 'message' => 'wg no responde'],
    ]);

    // Que una compensación falle no puede detener las demás: parar aquí
    // dejaría además la interfaz huérfana en el router.
    expect($steps)->toContain('rollback_host_peer')
        ->and($steps)->toContain('rollback_router_vpn');

    $session = DeviceProvisioningSession::find($sessionId);
    expect($session->status)->toBe(ProvisioningStatus::ROLLED_BACK);

    // Y queda constancia explícita de que ese extremo necesita limpieza manual.
    $compensations = Audit::where('table_name', ProvisioningAuditor::TABLE_SESSIONS)
        ->where('operation', ProvisioningAuditor::COMPENSATED)
        ->get();

    $failed = $compensations->first(fn (Audit $a) => ($a->new_values['outcome'] ?? null) === 'failed');

    expect($failed)->not->toBeNull()
        ->and($failed->new_values['compensation'])->toBe('rollback_host_peer')
        ->and($failed->new_values['manual_cleanup'])->toBeTrue();
});

it('vence las tareas que ningún agente reportó y dispara la reversión', function () {
    $sessionId = startSession($this, $this->provisioner);

    // El agente reclama la tarea de aplicar la VPN y muere sin reportar.
    $body    = ['max' => 1];
    $headers = signedAgentHeaders($this->provisioner, 'POST', '/api/agent/tasks/claim', $body);
    $task    = $this->postJson('/api/agent/tasks/claim', $body, $headers)->json('data.tasks.0');

    $uri  = "/api/agent/tasks/{$task['id']}/report";
    $body = ['status' => 'succeeded', 'result' => fakeAgentTaskResult('identify_device')];
    $this->postJson($uri, $body, signedAgentHeaders($this->provisioner, 'POST', $uri, $body));

    $body    = ['max' => 1];
    $headers = signedAgentHeaders($this->provisioner, 'POST', '/api/agent/tasks/claim', $body);
    $applyTask = $this->postJson('/api/agent/tasks/claim', $body, $headers)->json('data.tasks.0');

    expect($applyTask['type'])->toBe('apply_router_vpn');

    // Se simula el paso del tiempo hasta pasado el vencimiento.
    ProvisioningTask::whereKey($applyTask['id'])
        ->update(['expires_at' => now()->subMinutes(10)]);

    app(ExpireStaleProvisioningTasks::class)->handle(app(ProvisioningAuditor::class));

    expect(ProvisioningTask::find($applyTask['id'])->status)->toBe(ProvisioningTaskStatus::EXPIRED);

    // Sin este vigilante la sesión se quedaría esperando para siempre, y con
    // ella una interfaz a medio crear en el equipo y una IP retenida.
    $session = DeviceProvisioningSession::find($sessionId);
    expect($session->status)->toBeIn([ProvisioningStatus::ROLLED_BACK, ProvisioningStatus::PROVISIONING_ROUTER]);

    driveProvisioningFlow($this, $this->provisioner, $this->vpnHost);

    expect(DeviceProvisioningSession::find($sessionId)->status)
        ->toBe(ProvisioningStatus::ROLLED_BACK);
});

it('vence también una tarea que ningún agente llegó a recoger', function () {
    $sessionId = startSession($this, $this->provisioner);

    // Tarea creada pero nunca reclamada: el agente estaba caído o revocado.
    $pending = ProvisioningTask::where('session_id', $sessionId)->firstOrFail();
    expect($pending->status)->toBe(ProvisioningTaskStatus::PENDING);

    ProvisioningTask::whereKey($pending->id)->update(['expires_at' => now()->subMinutes(10)]);

    app(ExpireStaleProvisioningTasks::class)->handle(app(ProvisioningAuditor::class));

    expect(ProvisioningTask::find($pending->id)->status)->toBe(ProvisioningTaskStatus::EXPIRED);

    $session = DeviceProvisioningSession::find($sessionId);
    // Nada que compensar todavía, así que muere sin reversión.
    expect($session->status)->toBe(ProvisioningStatus::FAILED);
});

it('cancelar un alta ya iniciada revierte en lugar de cerrarla sin más', function () {
    $sessionId = startSession($this, $this->provisioner);

    // Se avanza hasta tener la VPN aplicada en el router.
    $body    = ['max' => 1];
    $headers = signedAgentHeaders($this->provisioner, 'POST', '/api/agent/tasks/claim', $body);
    $task    = $this->postJson('/api/agent/tasks/claim', $body, $headers)->json('data.tasks.0');
    $uri     = "/api/agent/tasks/{$task['id']}/report";
    $body    = ['status' => 'succeeded', 'result' => fakeAgentTaskResult('identify_device')];
    $this->postJson($uri, $body, signedAgentHeaders($this->provisioner, 'POST', $uri, $body));

    $this->actingAs(makeSuperAdminEmployee(), 'sanctum')
        ->postJson("/api/admin/provisioning/sessions/{$sessionId}/cancel", [
            'reason' => 'Equipo equivocado.',
        ])
        ->assertOk();

    // Ya se había tocado el router, así que cancelar dispara la compensación:
    // cerrar la sesión sin revertir dejaría residuo en el equipo.
    $session = DeviceProvisioningSession::find($sessionId);
    expect($session->error_code)->toBe('CANCELLED_BY_ADMIN')
        ->and($session->status)->not->toBe(ProvisioningStatus::CANCELLED);

    driveProvisioningFlow($this, $this->provisioner, $this->vpnHost);

    expect(DeviceProvisioningSession::find($sessionId)->status)
        ->toBe(ProvisioningStatus::ROLLED_BACK);
});

it('cancelar un alta que no ha tocado nada la cierra directamente', function () {
    $sessionId = startSession($this, $this->provisioner);

    $this->actingAs(makeSuperAdminEmployee(), 'sanctum')
        ->postJson("/api/admin/provisioning/sessions/{$sessionId}/cancel")
        ->assertOk();

    expect(DeviceProvisioningSession::find($sessionId)->status)
        ->toBe(ProvisioningStatus::CANCELLED);
});
