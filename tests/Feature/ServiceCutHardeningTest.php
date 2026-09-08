<?php

use App\Jobs\ProcessAutoReactivation;
use App\Jobs\ProcessClientSuspension;
use App\Models\Audit;
use App\Models\AutomationSetting;
use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\ClientServiceInterruption;
use App\Models\ClientWhitelist;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Wallet;
use App\Services\AutoBillingService;
use App\Services\ClientSuspensionService;
use App\Services\MikroTikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/**
 * Endurecimiento del módulo de cortes.
 *
 * Cubre lo que el informe "Anatomía del Corte" señalaba como no verificado:
 * que el corte llegue a aplicarse en la red, que no se corte a ciegas con el
 * router caído, que la lista blanca valga también en la vía manual y que la
 * reactivación no quede bloqueada por deuda que aún no vence.
 */

function makeCutAutomation(bool $enabled = true, int $graceDays = 3): void
{
    AutomationSetting::updateOrCreate(
        ['key' => 'client_suspension'],
        [
            'name'            => 'Suspensión Automática de Clientes',
            'description'     => 'Test',
            'job_class'       => ProcessClientSuspension::class,
            'queue'           => 'suspensions',
            'enabled'         => $enabled,
            'schedule_type'   => 'daily',
            'schedule_config' => ['time' => '04:00'],
            'params'          => ['grace_days' => $graceDays],
            'params_schema'   => ['grace_days' => ['type' => 'integer', 'min' => 0, 'max' => 30, 'required' => true]],
        ]
    );
    AutomationSetting::flushCache();
}

function makeOverdueClient(string $status = Invoice::STATUS_FAILED, int $graceDays = 3): Client
{
    $client = Client::factory()->active()->create(['ip' => '10.20.30.' . random_int(2, 250)]);
    $plan   = Plan::factory()->create();

    $clientPlan = ClientPlan::create([
        'client_id'         => $client->id,
        'plan_id'           => $plan->id,
        'start_date'        => now()->subMonths(2)->toDateString(),
        'billing_cycle'     => 'monthly',
        'status'            => 'active',
        'next_billing_date' => now()->addMonth()->toDateString(),
        'current_price'     => 25.00,
    ]);

    Invoice::create([
        'client_id'      => $client->id,
        'client_plan_id' => $clientPlan->id,
        'invoice_number' => 'INV-H-' . uniqid(),
        'issue_date'     => now()->subDays($graceDays + 10)->toDateString(),
        'due_date'       => now()->subDays($graceDays + 5)->toDateString(),
        'amount'         => 25.00,
        'tax_amount'     => 0,
        'total_amount'   => 25.00,
        'status'         => $status,
    ]);

    return $client;
}

function runCutJob(): void
{
    app(ProcessClientSuspension::class)->handle(
        app(ClientSuspensionService::class),
        app(AutoBillingService::class),
        app(MikroTikService::class)
    );
}

beforeEach(function () {
    // El último intento de cobro siempre falla: fuerza el camino del corte.
    $this->mock(AutoBillingService::class, function (MockInterface $m) {
        $m->shouldReceive('processInvoicePayment')->andReturn(['success' => false, 'error' => 'mocked']);
    });
});

/* -------------------------------------------------------------------------- */
/*  M-01 — El corte se verifica en la red, no solo en la lista                */
/* -------------------------------------------------------------------------- */

describe('Verificación de efectividad del corte', function () {

    it('marca el corte como enforced cuando hay regla de firewall que aplica la lista', function () {
        $this->mock(MikroTikService::class, function (MockInterface $m) {
            $m->shouldReceive('getSystemInfo')->andReturn(['uptime' => '1d']);
            $m->shouldReceive('addIpToAddressList')->andReturn(['success' => true]);
            $m->shouldReceive('verifyAddressListEnforcement')->andReturn([
                'state' => 'enforced', 'entry_found' => true, 'filter_rule_found' => true,
            ]);
        });

        makeCutAutomation();
        $client = makeOverdueClient();

        runCutJob();

        $ventana = ClientServiceInterruption::where('client_id', $client->id)->first();

        expect($ventana)->not->toBeNull();
        expect($ventana->enforcement_state)->toBe(ClientServiceInterruption::ENFORCEMENT_ENFORCED);
        expect($ventana->isEnforced())->toBeTrue();
    });

    it('detecta el corte que entra en la lista pero no tiene regla que lo aplique', function () {
        $this->mock(MikroTikService::class, function (MockInterface $m) {
            $m->shouldReceive('getSystemInfo')->andReturn(['uptime' => '1d']);
            $m->shouldReceive('addIpToAddressList')->andReturn(['success' => true]);
            // La IP entra en la lista, pero ninguna regla de firewall la usa:
            // el abonado sigue navegando aunque figure como cortado.
            $m->shouldReceive('verifyAddressListEnforcement')->andReturn([
                'state' => 'no_filter_rule', 'entry_found' => true, 'filter_rule_found' => false,
            ]);
        });

        makeCutAutomation();
        $client = makeOverdueClient();

        runCutJob();

        $ventana = ClientServiceInterruption::where('client_id', $client->id)->first();

        expect($ventana->enforcement_state)->toBe(ClientServiceInterruption::ENFORCEMENT_NO_FILTER_RULE);
        expect($ventana->isEnforced())->toBeFalse();

        // Y queda en la auditoría, no solo en el log.
        $audit = Audit::forRecord('clients', $client->id)->where('operation', 'SUSPEND_AUTO_OP')->first();
        expect($audit->new_values['enforcement_state'])->toBe('no_filter_rule');
    });

    it('el corte sin IP queda marcado como no efectivo', function () {
        $this->mock(MikroTikService::class, function (MockInterface $m) {
            $m->shouldReceive('getSystemInfo')->andReturn(['uptime' => '1d']);
        });

        makeCutAutomation();
        $client = makeOverdueClient();
        // La columna `ip` es NOT NULL en el esquema: un cliente "sin IP" se
        // representa con la cadena vacía, que es lo que ve el flujo de corte.
        $client->update(['ip' => '']);

        runCutJob();

        $ventana = ClientServiceInterruption::where('client_id', $client->id)->first();
        expect($ventana->enforcement_state)->toBe(ClientServiceInterruption::ENFORCEMENT_NO_IP);
    });
});

/* -------------------------------------------------------------------------- */
/*  M-02 — Compuerta de salud del router                                      */
/* -------------------------------------------------------------------------- */

describe('Compuerta de salud del router', function () {

    it('aborta la corrida sin suspender a nadie cuando MikroTik no responde', function () {
        $this->mock(MikroTikService::class, function (MockInterface $m) {
            $m->shouldReceive('getSystemInfo')->andReturn([]); // router mudo
        });

        makeCutAutomation();
        $client = makeOverdueClient();

        runCutJob();

        // Antes se suspendía igualmente: el cliente dejaba de facturarse y
        // seguía navegando.
        expect(strtoupper($client->fresh()->service_status))->not->toBe('SUSPENDED');
        expect(ClientServiceInterruption::where('client_id', $client->id)->count())->toBe(0);
    });

    it('aborta también cuando la comprobación del router lanza una excepción', function () {
        $this->mock(MikroTikService::class, function (MockInterface $m) {
            $m->shouldReceive('getSystemInfo')->andThrow(new RuntimeException('Sin conexión'));
        });

        makeCutAutomation();
        $client = makeOverdueClient();

        runCutJob();

        expect(strtoupper($client->fresh()->service_status))->not->toBe('SUSPENDED');
    });
});

/* -------------------------------------------------------------------------- */
/*  M-05 — La cohorte ya no depende de que los cobros marquen `failed`        */
/* -------------------------------------------------------------------------- */

describe('Cohorte independiente de los cobros automáticos', function () {

    it('suspende por una factura PENDING vencida más allá de la gracia', function () {
        $this->mock(MikroTikService::class, function (MockInterface $m) {
            $m->shouldReceive('getSystemInfo')->andReturn(['uptime' => '1d']);
            $m->shouldReceive('addIpToAddressList')->andReturn(['success' => true]);
            $m->shouldReceive('verifyAddressListEnforcement')->andReturn(['state' => 'enforced']);
        });

        makeCutAutomation();
        // Con los cobros apagados esta factura nunca habría pasado a `failed`,
        // así que el cliente jamás se habría suspendido.
        $client = makeOverdueClient(Invoice::STATUS_PENDING);

        runCutJob();

        expect(strtoupper($client->fresh()->service_status))->toBe('SUSPENDED');
    });

    it('no suspende por una factura pendiente que aún no ha vencido', function () {
        $this->mock(MikroTikService::class, function (MockInterface $m) {
            $m->shouldReceive('getSystemInfo')->andReturn(['uptime' => '1d']);
            $m->shouldReceive('addIpToAddressList')->andReturn(['success' => true]);
            $m->shouldReceive('verifyAddressListEnforcement')->andReturn(['state' => 'enforced']);
        });

        makeCutAutomation();
        $client = Client::factory()->active()->create(['ip' => '10.20.40.5']);
        $plan   = Plan::factory()->create();
        $cp = ClientPlan::create([
            'client_id' => $client->id, 'plan_id' => $plan->id,
            'start_date' => now()->subMonth()->toDateString(), 'billing_cycle' => 'monthly',
            'status' => 'active', 'next_billing_date' => now()->addMonth()->toDateString(),
            'current_price' => 25.00,
        ]);
        Invoice::create([
            'client_id' => $client->id, 'client_plan_id' => $cp->id,
            'invoice_number' => 'INV-FUT-' . uniqid(),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'amount' => 25.00, 'tax_amount' => 0, 'total_amount' => 25.00,
            'status' => Invoice::STATUS_PENDING,
        ]);

        runCutJob();

        expect(strtoupper($client->fresh()->service_status))->toBe('ACTIVE');
    });
});

/* -------------------------------------------------------------------------- */
/*  M-08 — La reactivación mira la deuda vencida, no todo lo emitido          */
/* -------------------------------------------------------------------------- */

describe('Reactivación contra deuda vencida', function () {

    it('reactiva aunque quede una factura futura sin pagar', function () {
        $this->mock(MikroTikService::class, function (MockInterface $m) {
            $m->shouldReceive('getSystemInfo')->andReturn(['uptime' => '1d']);
            $m->shouldReceive('removeIpFromAddressList')->andReturn(['success' => true]);
        });

        $client = Client::factory()->suspended()->create(['ip' => '10.20.50.9']);
        $plan   = Plan::factory()->create();
        $cp = ClientPlan::create([
            'client_id' => $client->id, 'plan_id' => $plan->id,
            'start_date' => now()->subMonths(2)->toDateString(), 'billing_cycle' => 'monthly',
            'status' => 'suspended', 'next_billing_date' => now()->addMonth()->toDateString(),
            'current_price' => 25.00,
        ]);

        // Factura futura, todavía no exigible: no debe condicionar el alta.
        Invoice::create([
            'client_id' => $client->id, 'client_plan_id' => $cp->id,
            'invoice_number' => 'INV-NEXT-' . uniqid(),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(15)->toDateString(),
            'amount' => 25.00, 'tax_amount' => 0, 'total_amount' => 25.00,
            'status' => Invoice::STATUS_PENDING,
        ]);

        Wallet::create(['client_id' => $client->id, 'balance' => 0]);

        app(ProcessAutoReactivation::class, ['client' => $client])
            ->handle(app(ClientSuspensionService::class), app(AutoBillingService::class));

        expect(strtoupper($client->fresh()->service_status))->toBe('ACTIVE');
    });
});

/* -------------------------------------------------------------------------- */
/*  M-12 — Una baja no se reactiva                                            */
/* -------------------------------------------------------------------------- */

describe('Integridad de la reactivación', function () {

    it('rechaza reactivar a un cliente dado de baja', function () {
        $this->mock(MikroTikService::class, function (MockInterface $m) {
            $m->shouldReceive('getSystemInfo')->andReturn(['uptime' => '1d']);
            $m->shouldReceive('removeIpFromAddressList')->andReturn(['success' => true]);
        });

        $client = Client::factory()->create(['service_status' => 'CANCELLED', 'ip' => '10.20.60.4']);

        $result = app(ClientSuspensionService::class)->reactivateClient($client, 'intento indebido');

        expect($result['success'])->toBeFalse();
        expect($result['code'])->toBe('CANCELLED');
        expect(strtoupper($client->fresh()->service_status))->toBe('CANCELLED');
    });
});

/* -------------------------------------------------------------------------- */
/*  M-03 — La vía manual: lista blanca y reconfirmación de contraseña         */
/* -------------------------------------------------------------------------- */

describe('Corte manual endurecido', function () {

    beforeEach(function () {
        $this->mock(MikroTikService::class, function (MockInterface $m) {
            $m->shouldReceive('getSystemInfo')->andReturn(['uptime' => '1d']);
            $m->shouldReceive('addIpToAddressList')->andReturn(['success' => true]);
            $m->shouldReceive('verifyAddressListEnforcement')->andReturn(['state' => 'enforced']);
        });

        $this->admin = makeSuperAdminEmployee(['password' => 'clave-operador']);
        RateLimiter::clear('confirmar-clave:' . $this->admin->id);
    });

    it('no corta sin reconfirmar la contraseña', function () {
        $client = Client::factory()->active()->create(['ip' => '10.20.70.2']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/clientes/{$client->id}/suspend")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PASSWORD_CONFIRMATION_REQUIRED');

        expect(strtoupper($client->fresh()->service_status))->toBe('ACTIVE');
    });

    it('no corta a un cliente protegido por la lista blanca', function () {
        $client = Client::factory()->active()->create(['ip' => '10.20.70.3']);

        ClientWhitelist::create([
            'client_id'     => $client->id,
            'added_at'      => now(),
            'authorized_by' => $this->admin->id,
            'reason'        => 'Cliente institucional',
            'active'        => true,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/clientes/{$client->id}/suspend", ['password' => 'clave-operador'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'WHITELISTED');

        expect(strtoupper($client->fresh()->service_status))->toBe('ACTIVE');

        expect(Audit::forRecord('clients', $client->id)
            ->where('operation', 'SUSPEND_BLOCKED_WHITELIST')->exists())->toBeTrue();
    });

    it('corta con contraseña correcta y guarda el motivo y el ejecutor reales', function () {
        $client = Client::factory()->active()->create(['ip' => '10.20.70.4']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/clientes/{$client->id}/suspend", [
                'password' => 'clave-operador',
                'reason'   => 'Acuerdo de pago incumplido tras tres avisos',
            ])
            ->assertStatus(200);

        expect(strtoupper($client->fresh()->service_status))->toBe('SUSPENDED');

        $audit = Audit::forRecord('clients', $client->id)->where('operation', 'SUSPEND_TECH_OP')->first();

        expect($audit->new_values['reason'])->toBe('Acuerdo de pago incumplido tras tres avisos');
        // El ejecutor ya no queda firmado como "Unknown": Employee usa `nombre`.
        expect($audit->new_values['executor'])->toBe($this->admin->nombre);

        $ventana = ClientServiceInterruption::where('client_id', $client->id)->first();
        expect($ventana->suspension_reason)->toBe('Acuerdo de pago incumplido tras tres avisos');
        expect($ventana->enforcement_state)->toBe(ClientServiceInterruption::ENFORCEMENT_ENFORCED);
    });
});

/* -------------------------------------------------------------------------- */
/*  M-10 — El historial de cortes es consultable                              */
/* -------------------------------------------------------------------------- */

describe('Historial de cortes', function () {

    it('devuelve la línea de tiempo del cliente con su resumen', function () {
        $employee = makeSuperAdminEmployee();
        $client   = Client::factory()->active()->create();

        ClientServiceInterruption::create([
            'client_id'           => $client->id,
            'type'                => 'suspension',
            'suspended_at'        => now()->subDays(5),
            'reactivated_at'      => now()->subDays(3),
            'suspension_reason'   => 'Factura vencida',
            'reactivation_reason' => 'Pago recibido',
            'suspended_by'        => 'system_auto',
            'source'              => 'auto',
            'enforcement_state'   => ClientServiceInterruption::ENFORCEMENT_NO_FILTER_RULE,
        ]);

        $res = $this->actingAs($employee, 'sanctum')
            ->getJson("/api/admin/clientes/{$client->id}/interrupciones");

        $res->assertStatus(200)
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.not_enforced', 1)
            ->assertJsonPath('data.0.enforced', false)
            ->assertJsonPath('data.0.suspension_reason', 'Factura vencida');

        expect($res->json('data.0.duration_hours'))->toBeGreaterThan(0);
    });

    it('devuelve 404 si el cliente no existe', function () {
        $this->actingAs(makeSuperAdminEmployee(), 'sanctum')
            ->getJson('/api/admin/clientes/999999/interrupciones')
            ->assertStatus(404);
    });
});
