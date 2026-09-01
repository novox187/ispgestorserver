<?php

use App\Jobs\MonitorMikrotikConnectivityJob;
use App\Models\Audit;
use App\Models\DeviceProvisioningSession;
use App\Models\Employee;
use App\Models\MikrotikRouter;
use App\Services\MikrotikHealthChecker;
use App\Services\Provisioning\ProvisioningAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/**
 * El requisito es que TODO el proceso quede en la tabla de auditorías. Estos
 * tests comprueban las dos mitades de eso: que el alta deja su traza completa,
 * y que al añadirla no se ha ahogado la tabla con ruido de alta frecuencia
 * —que es la forma habitual de que una auditoría deje de servir para nada.
 */

beforeEach(function () {
    $this->provisioner = makeProvisioningAgent('provisioner');
    $this->vpnHost     = makeProvisioningAgent('vpn_host');
});

function auditedOperations(int $sessionId): array
{
    return Audit::forRecord(ProvisioningAuditor::TABLE_SESSIONS, $sessionId)
        ->orderBy('id')
        ->pluck('operation')
        ->all();
}

it('deja la traza completa de un alta correcta', function () {
    $this->mock(MikrotikHealthChecker::class, function (MockInterface $m) {
        $m->shouldReceive('check')->andReturn(['ok' => true, 'error' => null]);
    });

    $body = [
        'detection_method' => 'mndp',
        'mac_address'      => '18:FD:74:AA:BB:CC',
        'lan_ip'           => '192.168.88.1',
    ];
    $sessionId = (int) $this->postJson(
        '/api/agent/devices/detected',
        $body,
        signedAgentHeaders($this->provisioner, 'POST', '/api/agent/devices/detected', $body),
    )->json('data.session_id');

    driveProvisioningFlow($this, $this->provisioner, $this->vpnHost);

    expect(auditedOperations($sessionId))->toBe([
        ProvisioningAuditor::DETECTED,
        ProvisioningAuditor::IDENTIFIED,
        ProvisioningAuditor::ROUTER_APPLIED,
        ProvisioningAuditor::HOST_APPLIED,
        ProvisioningAuditor::VERIFIED,
        ProvisioningAuditor::COMPLETED,
    ]);
});

it('identifica al agente que ejecutó cada paso', function () {
    $this->mock(MikrotikHealthChecker::class, function (MockInterface $m) {
        $m->shouldReceive('check')->andReturn(['ok' => true, 'error' => null]);
    });

    $body = ['detection_method' => 'mndp', 'mac_address' => '18:FD:74:AA:BB:CC', 'lan_ip' => '192.168.88.1'];
    $sessionId = (int) $this->postJson(
        '/api/agent/devices/detected',
        $body,
        signedAgentHeaders($this->provisioner, 'POST', '/api/agent/devices/detected', $body),
    )->json('data.session_id');

    driveProvisioningFlow($this, $this->provisioner, $this->vpnHost);

    $detected = Audit::forRecord(ProvisioningAuditor::TABLE_SESSIONS, $sessionId)
        ->where('operation', ProvisioningAuditor::DETECTED)
        ->first();

    // Un agente no es un usuario del sistema: `user_id` queda a null y su
    // identidad va en `executor`. Sin esto, un paso ejecutado por un agente
    // sería indistinguible de uno del scheduler.
    expect($detected->user_id)->toBeNull()
        ->and($detected->new_values['executor'])
        ->toBe("agent:{$this->provisioner['agent']->id}:provisioner")
        ->and($detected->new_values)->toHaveKey('timestamp');
});

it('registra quién aprobó un alta cuando la aprobación es manual', function () {
    App\Models\AutomationSetting::create([
        'key' => App\Services\Provisioning\ProvisioningSettings::SETTING_KEY,
        'name' => 'Alta automática', 'job_class' => App\Jobs\ExpireStaleProvisioningTasks::class,
        'queue' => 'provisioning', 'enabled' => true,
        'schedule_type' => 'every_five_minutes', 'params' => ['auto_approve' => false],
    ]);

    $body = ['detection_method' => 'mndp', 'mac_address' => '18:FD:74:AA:BB:CC', 'lan_ip' => '192.168.88.1'];
    $sessionId = (int) $this->postJson(
        '/api/agent/devices/detected',
        $body,
        signedAgentHeaders($this->provisioner, 'POST', '/api/agent/devices/detected', $body),
    )->json('data.session_id');

    driveProvisioningFlow($this, $this->provisioner, $this->vpnHost);

    $employee = makeSuperAdminEmployee();
    $this->actingAs($employee, 'sanctum')
        ->postJson("/api/admin/provisioning/sessions/{$sessionId}/approve")
        ->assertOk();

    $approval = Audit::forRecord(ProvisioningAuditor::TABLE_SESSIONS, $sessionId)
        ->where('operation', ProvisioningAuditor::APPROVED)
        ->first();

    expect($approval)->not->toBeNull()
        ->and($approval->user_id)->toBe($employee->id)
        ->and($approval->user_type)->toBe(Employee::class);
});

it('registra el fallo y la reversión de un alta que no prospera', function () {
    $body = ['detection_method' => 'mndp', 'mac_address' => '18:FD:74:AA:BB:CC', 'lan_ip' => '192.168.88.1'];
    $sessionId = (int) $this->postJson(
        '/api/agent/devices/detected',
        $body,
        signedAgentHeaders($this->provisioner, 'POST', '/api/agent/devices/detected', $body),
    )->json('data.session_id');

    driveProvisioningFlow($this, $this->provisioner, $this->vpnHost, failAt: [
        'apply_host_peer' => ['code' => 'WG_SET_FAILED', 'message' => 'wg set devolvió 1'],
    ]);

    $operations = auditedOperations($sessionId);

    expect($operations)->toContain(ProvisioningAuditor::STEP_FAILED)
        ->and($operations)->toContain(ProvisioningAuditor::COMPENSATED)
        ->and($operations)->toContain(ProvisioningAuditor::ROLLED_BACK)
        ->and($operations)->not->toContain(ProvisioningAuditor::COMPLETED);
});

it('no filtra secretos a la tabla de auditoría', function () {
    $this->mock(MikrotikHealthChecker::class, function (MockInterface $m) {
        $m->shouldReceive('check')->andReturn(['ok' => true, 'error' => null]);
    });

    $body = ['detection_method' => 'mndp', 'mac_address' => '18:FD:74:AA:BB:CC', 'lan_ip' => '192.168.88.1'];
    $this->postJson(
        '/api/agent/devices/detected',
        $body,
        signedAgentHeaders($this->provisioner, 'POST', '/api/agent/devices/detected', $body),
    );

    driveProvisioningFlow($this, $this->provisioner, $this->vpnHost);

    $everything = Audit::all()
        ->map(fn (Audit $a) => json_encode([$a->old_values, $a->new_values]))
        ->implode(' ');

    // El trait descarta los campos de `$hidden`, y ahí están el secreto HMAC
    // del agente, el contexto cifrado de la sesión y la contraseña del router.
    expect($everything)->not->toContain($this->provisioner['secret'])
        ->and($everything)->not->toContain('api_password')
        ->and($everything)->not->toContain('lan_credentials');

    $router = MikrotikRouter::first();
    expect($everything)->not->toContain((string) $router->password);
});

// ── El agujero que existía antes de este módulo ──────────────────────────────

it('audita el alta manual de un router, que antes no dejaba rastro', function () {
    $this->actingAs(makeSuperAdminEmployee(), 'sanctum')
        ->postJson('/api/admin/mikrotik-routers', [
            'name'     => 'Router manual',
            'host'     => '192.168.20.1',
            'username' => 'admin',
            'password' => 'secreto',
        ])
        ->assertStatus(201);

    $router = MikrotikRouter::first();

    $audit = Audit::forRecord('mikrotik_routers', $router->id)
        ->where('operation', 'INSERT')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->new_values['host'])->toBe('192.168.20.1')
        // La contraseña está en `$hidden`, así que el trait la excluye.
        ->and($audit->new_values)->not->toHaveKey('password');
});

it('audita la despromoción del router principal, que el update masivo se saltaba', function () {
    $first = MikrotikRouter::create([
        'name' => 'Primero', 'host' => '10.0.0.1', 'username' => 'a', 'password' => 'b',
    ]);
    expect($first->fresh()->is_primary)->toBeTrue();

    $second = MikrotikRouter::create([
        'name' => 'Segundo', 'host' => '10.0.0.2', 'username' => 'a', 'password' => 'b',
        'is_primary' => true,
    ]);

    expect($first->fresh()->is_primary)->toBeFalse();

    // El hook usa un update() masivo que no dispara eventos Eloquent: sin el
    // registro explícito, el cambio de router principal —que hace que todo el
    // sistema opere contra otro equipo— no dejaría ningún rastro.
    $audit = Audit::forRecord('mikrotik_routers', $first->id)
        ->where('operation', 'PRIMARY_DEMOTED')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->old_values['is_primary'])->toBeTrue()
        ->and($audit->new_values['is_primary'])->toBeFalse()
        ->and($audit->new_values['promoted_router_id'])->toBe($second->id);
});

it('el monitor de conectividad no genera ruido en la auditoría', function () {
    $router = MikrotikRouter::create([
        'name' => 'Router', 'host' => '10.0.0.1', 'username' => 'a', 'password' => 'b',
    ]);

    $baseline = Audit::forRecord('mikrotik_routers', $router->id)->count();

    $this->mock(MikrotikHealthChecker::class, function (MockInterface $m) {
        $m->shouldReceive('check')->andReturn(['ok' => false, 'error' => 'timeout']);
    });

    // El monitor corre cada 5 minutos y reescribe los campos de salud con
    // forceFill()->save(), que SÍ dispara eventos. Sin excluir esos campos,
    // esto serían cientos de filas al día por router y ahogaría los cambios
    // que de verdad importan.
    foreach (range(1, 5) as $ignored) {
        (new MonitorMikrotikConnectivityJob())->handle(app(MikrotikHealthChecker::class));
    }

    expect($router->fresh()->consecutive_failures)->toBe(5)
        ->and($router->fresh()->connectivity_status)->toBe('disconnected')
        ->and(Audit::forRecord('mikrotik_routers', $router->id)->count())->toBe($baseline);
});

it('sí audita un cambio real de credenciales o de host', function () {
    $router = MikrotikRouter::create([
        'name' => 'Router', 'host' => '10.0.0.1', 'username' => 'a', 'password' => 'b',
    ]);

    $router->update(['host' => '10.77.0.5', 'username' => 'ispgestor-api']);

    $audit = Audit::forRecord('mikrotik_routers', $router->id)
        ->where('operation', 'UPDATE')
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->old_values['host'])->toBe('10.0.0.1')
        ->and($audit->new_values['host'])->toBe('10.77.0.5')
        ->and($audit->new_values['username'])->toBe('ispgestor-api');
});

it('permite reconstruir el historial de un alta desde el visor de auditorías', function () {
    $this->mock(MikrotikHealthChecker::class, function (MockInterface $m) {
        $m->shouldReceive('check')->andReturn(['ok' => true, 'error' => null]);
    });

    $body = ['detection_method' => 'mndp', 'mac_address' => '18:FD:74:AA:BB:CC', 'lan_ip' => '192.168.88.1'];
    $sessionId = (int) $this->postJson(
        '/api/agent/devices/detected',
        $body,
        signedAgentHeaders($this->provisioner, 'POST', '/api/agent/devices/detected', $body),
    )->json('data.session_id');

    driveProvisioningFlow($this, $this->provisioner, $this->vpnHost);

    // El visor existente no necesita cambios: como `record_id` es el id de la
    // sesión, filtrar por tabla y registro devuelve el alta entera.
    $response = $this->actingAs(makeSuperAdminEmployee(), 'sanctum')
        ->getJson('/api/admin/audits?table_name=' . ProvisioningAuditor::TABLE_SESSIONS
            . '&record_id=' . $sessionId)
        ->assertOk();

    expect($response->json('total'))->toBe(6);

    // Y el detalle de la sesión trae el mismo historial ya resuelto.
    $detail = $this->actingAs(makeSuperAdminEmployee(), 'sanctum')
        ->getJson("/api/admin/provisioning/sessions/{$sessionId}")
        ->assertOk();

    expect($detail->json('data.audit_trail'))->toHaveCount(6)
        ->and($detail->json('data.tasks'))->toHaveCount(6);
});
