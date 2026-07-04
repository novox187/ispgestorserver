<?php

use App\Models\Audit;
use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\Employee;
use App\Models\Plan;
use App\Services\ClientSuspensionService;
use App\Services\MikroTikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/* -------------------------------------------------------------------------- */
/*  1) Inmutabilidad de los registros de auditoría                            */
/* -------------------------------------------------------------------------- */

describe('Inmutabilidad de registros de auditoría', function () {

    it('bloquea la modificación de un registro de auditoría', function () {
        $audit = Audit::create([
            'table_name' => 'clients',
            'operation'  => 'INSERT',
            'record_id'  => '1',
            'new_values' => ['foo' => 'bar'],
        ]);

        expect(fn () => $audit->update(['operation' => 'HACKED']))
            ->toThrow(LogicException::class);

        expect($audit->fresh()->operation)->toBe('INSERT');
    });

    it('bloquea la eliminación de un registro de auditoría', function () {
        $audit = Audit::create([
            'table_name' => 'clients',
            'operation'  => 'INSERT',
            'record_id'  => '1',
        ]);

        expect(fn () => $audit->delete())->toThrow(LogicException::class);
        expect(Audit::find($audit->id))->not->toBeNull();
    });
});

/* -------------------------------------------------------------------------- */
/*  2) Trait Auditable: filtrado de ruido                                     */
/* -------------------------------------------------------------------------- */

describe('Trait Auditable', function () {

    it('no registra auditoría cuando solo cambia updated_at (touch)', function () {
        $client = Client::factory()->active()->create();
        $countBefore = Audit::forRecord('clients', $client->id)->count();

        $client->touch();

        expect(Audit::forRecord('clients', $client->id)->count())->toBe($countBefore);
    });

    it('excluye updated_at de los cambios registrados en UPDATE', function () {
        $client = Client::factory()->active()->create();

        $client->update(['full_name' => 'Nombre Auditado']);

        $audit = Audit::forRecord('clients', $client->id)
            ->where('operation', 'UPDATE')
            ->latest('id')
            ->first();

        expect($audit)->not->toBeNull();
        expect($audit->new_values)->toHaveKey('full_name');
        expect($audit->new_values)->not->toHaveKey('updated_at');
    });
});

/* -------------------------------------------------------------------------- */
/*  3) Cortes manuales fallidos quedan auditados                              */
/* -------------------------------------------------------------------------- */

describe('Auditoría de cortes manuales fallidos', function () {

    it('registra SUSPEND_FAILED_OP cuando MikroTik falla durante la ejecución', function () {
        $this->mock(MikroTikService::class, function (MockInterface $m) {
            $m->shouldReceive('getSystemInfo')->andReturn(['uptime' => '1d']);
            $m->shouldReceive('addIpToAddressList')->andReturn([
                'success' => false,
                'message' => 'Router inaccesible',
            ]);
        });

        $employee = makeSuperAdminEmployee();
        $client   = Client::factory()->active()->create();

        $res = $this->actingAs($employee, 'sanctum')
            ->postJson("/api/admin/clientes/{$client->id}/suspend");

        $res->assertStatus(500);

        // El estado del cliente NO cambió (rollback)
        expect(strtoupper($client->fresh()->service_status))->not->toBe('SUSPENDED');

        // …pero el intento fallido quedó auditado fuera de la transacción
        $audit = Audit::forRecord('clients', $client->id)
            ->where('operation', 'SUSPEND_FAILED_OP')
            ->first();

        expect($audit)->not->toBeNull();
        expect($audit->new_values['failed_stage'])->toBe('execution');
        expect($audit->new_values['error'])->toContain('Router inaccesible');
        expect($audit->user_id)->toBe($employee->id);
        expect($audit->user_type)->toBe(Employee::class);
    });

    it('registra SUSPEND_FAILED_OP cuando no hay conectividad con MikroTik', function () {
        $this->mock(MikroTikService::class, function (MockInterface $m) {
            $m->shouldReceive('getSystemInfo')->andThrow(new RuntimeException('Sin conexión'));
        });

        $employee = makeSuperAdminEmployee();
        $client   = Client::factory()->active()->create();

        $this->actingAs($employee, 'sanctum')
            ->postJson("/api/admin/clientes/{$client->id}/suspend")
            ->assertStatus(503);

        $audit = Audit::forRecord('clients', $client->id)
            ->where('operation', 'SUSPEND_FAILED_OP')
            ->first();

        expect($audit)->not->toBeNull();
        expect($audit->new_values['failed_stage'])->toBe('mikrotik_connectivity');
    });

    it('registra ACTIVATE_FAILED_OP cuando la reactivación manual falla', function () {
        $this->mock(MikroTikService::class, function (MockInterface $m) {
            $m->shouldReceive('getSystemInfo')->andReturn(['uptime' => '1d']);
            $m->shouldReceive('removeIpFromAddressList')->andReturn([
                'success' => false,
                'message' => 'Router inaccesible',
            ]);
        });

        $employee = makeSuperAdminEmployee();
        $client   = Client::factory()->suspended()->create(['ip' => '192.168.20.200']);

        $this->actingAs($employee, 'sanctum')
            ->postJson("/api/admin/clientes/{$client->id}/activate")
            ->assertStatus(500);

        $audit = Audit::forRecord('clients', $client->id)
            ->where('operation', 'ACTIVATE_FAILED_OP')
            ->first();

        expect($audit)->not->toBeNull();
        expect($audit->new_values['failed_stage'])->toBe('execution');
    });
});

/* -------------------------------------------------------------------------- */
/*  4) Trazabilidad de planes afectados y user_type en flujos de servicio     */
/* -------------------------------------------------------------------------- */

describe('Trazabilidad de cortes automáticos y bajas', function () {

    beforeEach(function () {
        $this->mock(MikroTikService::class, function (MockInterface $m) {
            $m->shouldReceive('addIpToAddressList')->andReturn(['success' => true]);
            $m->shouldReceive('removeIpFromAddressList')->andReturn(['success' => true]);
        });
    });

    it('SUSPEND_AUTO_OP registra los planes afectados por el corte', function () {
        $client = Client::factory()->active()->create(['service_status' => 'active']);
        $plan   = Plan::factory()->create();

        $clientPlan = ClientPlan::create([
            'client_id'         => $client->id,
            'plan_id'           => $plan->id,
            'start_date'        => now()->subMonth()->toDateString(),
            'billing_cycle'     => 'monthly',
            'status'            => 'active',
            'next_billing_date' => now()->addMonth()->toDateString(),
            'current_price'     => 25.00,
        ]);

        app(ClientSuspensionService::class)->suspendClient($client, 'Test corte automático');

        $audit = Audit::forRecord('clients', $client->id)
            ->where('operation', 'SUSPEND_AUTO_OP')
            ->first();

        expect($audit)->not->toBeNull();
        expect($audit->new_values['plans_affected'])->toContain($clientPlan->id);
        expect($clientPlan->fresh()->status)->toBe('suspended');
    });

    it('CANCEL_OP registra user_type del empleado que ejecuta la baja', function () {
        $employee = makeSuperAdminEmployee();
        $client   = Client::factory()->suspended()->create(['ip' => '192.168.20.201']);

        app(ClientSuspensionService::class)
            ->cancelClient($client, 'Baja de prueba', $employee->id, '10.0.0.1');

        $audit = Audit::forRecord('clients', $client->id)
            ->where('operation', 'CANCEL_OP')
            ->first();

        expect($audit)->not->toBeNull();
        expect($audit->user_id)->toBe($employee->id);
        expect($audit->user_type)->toBe(Employee::class);
    });
});

/* -------------------------------------------------------------------------- */
/*  5) Endpoints del visor de auditoría                                       */
/* -------------------------------------------------------------------------- */

describe('GET /admin/audits', function () {

    it('lista auditorías paginadas y filtra por operación', function () {
        $employee = makeSuperAdminEmployee();

        Audit::create(['table_name' => 'clients', 'operation' => 'SUSPEND_TECH_OP', 'record_id' => '1']);
        Audit::create(['table_name' => 'clients', 'operation' => 'ACTIVATE_TECH_OP', 'record_id' => '1']);
        Audit::create(['table_name' => 'invoices', 'operation' => 'INSERT', 'record_id' => '9']);

        $res = $this->actingAs($employee, 'sanctum')
            ->getJson('/api/admin/audits?operation=SUSPEND_TECH_OP');

        $res->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.operation', 'SUSPEND_TECH_OP')
            ->assertJsonPath('data.0.table_name', 'clients');
    });

    it('filtra por tabla y rango de fechas', function () {
        $employee = makeSuperAdminEmployee();

        Audit::create(['table_name' => 'clients', 'operation' => 'INSERT', 'record_id' => '1']);
        Audit::create(['table_name' => 'invoices', 'operation' => 'INSERT', 'record_id' => '2']);

        $res = $this->actingAs($employee, 'sanctum')
            ->getJson('/api/admin/audits?table_name=invoices&date_from=' . now()->toDateString() . '&date_to=' . now()->toDateString());

        $res->assertStatus(200)->assertJsonPath('total', 1);
        expect($res->json('data.0.table_name'))->toBe('invoices');
    });

    it('requiere autenticación', function () {
        $this->getJson('/api/admin/audits')->assertStatus(401);
    });

    it('rechaza parámetros de paginación fuera de rango', function () {
        $this->actingAs(makeSuperAdminEmployee(), 'sanctum')
            ->getJson('/api/admin/audits?per_page=5000')
            ->assertStatus(422);
    });
});

describe('GET /admin/audits/filters', function () {

    it('devuelve tablas y operaciones distintas', function () {
        $employee = makeSuperAdminEmployee();

        Audit::create(['table_name' => 'clients', 'operation' => 'SUSPEND_AUTO_OP', 'record_id' => '1']);
        Audit::create(['table_name' => 'clients', 'operation' => 'SUSPEND_AUTO_OP', 'record_id' => '2']);

        $res = $this->actingAs($employee, 'sanctum')
            ->getJson('/api/admin/audits/filters');

        $res->assertStatus(200);
        expect($res->json('tables'))->toContain('clients');
        expect($res->json('operations'))->toContain('SUSPEND_AUTO_OP');
    });
});

describe('GET /admin/clientes/{id}/audits', function () {

    it('devuelve el historial del cliente con el ejecutor resuelto', function () {
        $employee = makeSuperAdminEmployee();
        $client   = Client::factory()->active()->create();

        Audit::create([
            'table_name' => 'clients',
            'operation'  => 'SUSPEND_TECH_OP',
            'record_id'  => (string) $client->id,
            'new_values' => ['service_status' => 'suspended'],
            'user_id'    => $employee->id,
            'user_type'  => Employee::class,
            'ip_address' => '10.0.0.1',
        ]);

        $res = $this->actingAs($employee, 'sanctum')
            ->getJson("/api/admin/clientes/{$client->id}/audits");

        $res->assertStatus(200);

        $rows = collect($res->json('data'));
        $suspend = $rows->firstWhere('operation', 'SUSPEND_TECH_OP');

        expect($suspend)->not->toBeNull();
        expect($suspend['user_name'])->toBe($employee->nombre);
        expect($suspend['user_type'])->toBe('Employee');
    });

    it('devuelve 404 si el cliente no existe', function () {
        $this->actingAs(makeSuperAdminEmployee(), 'sanctum')
            ->getJson('/api/admin/clientes/999999/audits')
            ->assertStatus(404);
    });

    it('agrega al historial los eventos de tablas relacionadas (billetera, transacciones)', function () {
        $employee = makeSuperAdminEmployee();
        $client   = Client::factory()->active()->create();
        $otro     = Client::factory()->active()->create();

        // Billetera + transacción del cliente (el INSERT lo audita el trait)
        $wallet = \App\Models\Wallet::create(['client_id' => $client->id, 'balance' => 0]);
        $tx     = \App\Models\Transaction::create([
            'wallet_id' => $wallet->id,
            'type'      => 'deposit',
            'amount'    => 25.00,
            'status'    => 'completed',
            'reference' => 'TEST-' . uniqid(),
        ]);

        // Billetera de OTRO cliente: no debe aparecer en este historial
        $walletOtro = \App\Models\Wallet::create(['client_id' => $otro->id, 'balance' => 0]);

        $res = $this->actingAs($employee, 'sanctum')
            ->getJson("/api/admin/clientes/{$client->id}/audits?per_page=100");

        $res->assertStatus(200);
        $rows = collect($res->json('data'));

        expect($rows->contains(fn ($r) => $r['table_name'] === 'wallets' && $r['record_id'] === (string) $wallet->id))->toBeTrue();
        expect($rows->contains(fn ($r) => $r['table_name'] === 'transactions' && $r['record_id'] === (string) $tx->id))->toBeTrue();
        expect($rows->contains(fn ($r) => $r['table_name'] === 'wallets' && $r['record_id'] === (string) $walletOtro->id))->toBeFalse();
    });

    it('filtra el historial del cliente por tabla', function () {
        $employee = makeSuperAdminEmployee();
        $client   = Client::factory()->active()->create();
        \App\Models\Wallet::create(['client_id' => $client->id, 'balance' => 0]);

        $res = $this->actingAs($employee, 'sanctum')
            ->getJson("/api/admin/clientes/{$client->id}/audits?table_name=wallets");

        $res->assertStatus(200);
        $rows = collect($res->json('data'));
        expect($rows->isNotEmpty())->toBeTrue();
        expect($rows->every(fn ($r) => $r['table_name'] === 'wallets'))->toBeTrue();
    });
});
