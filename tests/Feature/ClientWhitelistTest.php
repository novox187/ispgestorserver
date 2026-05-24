<?php

use App\Jobs\ProcessClientSuspension;
use App\Models\Audit;
use App\Models\AutomationSetting;
use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\ClientWhitelist;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Role;
use App\Services\AutoBillingService;
use App\Services\ClientSuspensionService;
use App\Services\ClientWhitelistService;
use App\Services\MikroTikService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/* ────────────────────────────────────────────────────────────────────────── */
/*  Helpers                                                                  */
/* ────────────────────────────────────────────────────────────────────────── */

function makeSuperAdmin(): Employee
{
    $role = Role::firstOrCreate(
        ['slug' => 'super_admin'],
        ['nombre' => 'Super Admin', 'descripcion' => '']
    );
    return Employee::factory()->create(['role_id' => $role->id]);
}

function makeRegularEmployee(): Employee
{
    $role = Role::firstOrCreate(
        ['slug' => 'agent'],
        ['nombre' => 'Agente', 'descripcion' => 'sin privilegios']
    );
    return Employee::factory()->create(['role_id' => $role->id]);
}

function seedWhitelistSuspensionFlow(int $graceDays = 3): void
{
    AutomationSetting::updateOrCreate(
        ['key' => 'client_suspension'],
        [
            'name'          => 'Suspensión Automática',
            'description'   => 'Test',
            'job_class'     => ProcessClientSuspension::class,
            'queue'         => 'suspensions',
            'enabled'       => true,
            'schedule_type' => 'daily',
            'schedule_config' => ['time' => '02:00'],
            'params'        => ['grace_days' => $graceDays],
            'params_schema' => [
                'grace_days' => ['type' => 'integer', 'min' => 0, 'max' => 30, 'required' => true],
            ],
        ]
    );
    AutomationSetting::flushCache();
}

function makeOverdueInvoiceForClient(Client $client, string $dueDate): Invoice
{
    $plan = Plan::factory()->create();

    $clientPlan = ClientPlan::create([
        'client_id'         => $client->id,
        'plan_id'           => $plan->id,
        'start_date'        => Carbon::parse($dueDate)->subMonth()->toDateString(),
        'billing_cycle'     => 'monthly',
        'status'            => 'active',
        'next_billing_date' => Carbon::parse($dueDate)->addMonth()->toDateString(),
        'current_price'     => 25.00,
    ]);

    return Invoice::create([
        'client_id'      => $client->id,
        'client_plan_id' => $clientPlan->id,
        'invoice_number' => 'INV-WL-' . uniqid(),
        'issue_date'     => Carbon::parse($dueDate)->subDays(5)->toDateString(),
        'due_date'       => $dueDate,
        'amount'         => 25.00,
        'tax_amount'     => 0,
        'total_amount'   => 25.00,
        'status'         => Invoice::STATUS_FAILED,
    ]);
}

beforeEach(function () {
    $this->mock(MikroTikService::class, function (MockInterface $m) {
        $m->shouldReceive('addIpToAddressList')->andReturn(['success' => true]);
        $m->shouldReceive('removeIpFromAddressList')->andReturn(['success' => true]);
    });

    $this->mock(AutoBillingService::class, function (MockInterface $m) {
        $m->shouldReceive('processInvoicePayment')->andReturn([
            'success' => false,
            'error'   => 'mocked',
        ]);
    });
});

/* ────────────────────────────────────────────────────────────────────────── */
/*  1) Regla de negocio: protección contra suspensión                        */
/* ────────────────────────────────────────────────────────────────────────── */

describe('Regla de negocio: protección de suspensión por lista blanca', function () {

    it('NO suspende a un cliente en la lista blanca aunque tenga facturas vencidas', function () {
        Carbon::setTestNow(Carbon::parse('2026-06-20 02:00:00'));
        seedWhitelistSuspensionFlow(graceDays: 3);

        $admin   = makeSuperAdmin();
        $client  = Client::factory()->active()->create();
        $invoice = makeOverdueInvoiceForClient($client, '2026-06-10');

        // Cliente protegido
        app(ClientWhitelistService::class)->addClient(
            client: $client,
            authorizedBy: $admin,
            reason: 'Cliente VIP — acuerdo comercial',
        );

        app(ProcessClientSuspension::class)->handle(
            app(ClientSuspensionService::class),
            app(AutoBillingService::class),
        );

        $client->refresh();
        expect(strtoupper($client->service_status))->not->toBe('SUSPENDED');

        Carbon::setTestNow();
    });

    it('SÍ suspende a un cliente NO incluido en la lista cuando cumple las condiciones', function () {
        Carbon::setTestNow(Carbon::parse('2026-06-20 02:00:00'));
        seedWhitelistSuspensionFlow(graceDays: 3);

        $client  = Client::factory()->active()->create();
        $invoice = makeOverdueInvoiceForClient($client, '2026-06-10');

        // Cliente NO está en la lista blanca
        expect($client->isWhitelisted())->toBeFalse();

        app(ProcessClientSuspension::class)->handle(
            app(ClientSuspensionService::class),
            app(AutoBillingService::class),
        );

        $client->refresh();
        expect(strtoupper($client->service_status))->toBe('SUSPENDED');

        Carbon::setTestNow();
    });

    it('SÍ suspende cuando la inclusión en la lista blanca ha vencido', function () {
        Carbon::setTestNow(Carbon::parse('2026-06-20 02:00:00'));
        seedWhitelistSuspensionFlow(graceDays: 3);

        $admin   = makeSuperAdmin();
        $client  = Client::factory()->active()->create();
        $invoice = makeOverdueInvoiceForClient($client, '2026-06-10');

        // Inclusión que venció ayer
        ClientWhitelist::create([
            'client_id'     => $client->id,
            'added_at'      => Carbon::parse('2026-05-01'),
            'authorized_by' => $admin->id,
            'reason'        => 'Promoción de 30 días',
            'expires_at'    => Carbon::parse('2026-06-19 23:59:59'),
            'active'        => true,
        ]);

        expect($client->isWhitelisted())->toBeFalse();

        app(ProcessClientSuspension::class)->handle(
            app(ClientSuspensionService::class),
            app(AutoBillingService::class),
        );

        $client->refresh();
        expect(strtoupper($client->service_status))->toBe('SUSPENDED');

        Carbon::setTestNow();
    });

    it('el servicio devuelve whitelisted=true cuando se llama directamente a suspendClient', function () {
        $admin  = makeSuperAdmin();
        $client = Client::factory()->active()->create();

        app(ClientWhitelistService::class)->addClient(
            client: $client,
            authorizedBy: $admin,
            reason: 'Protegido por finanzas',
        );

        $result = app(ClientSuspensionService::class)->suspendClient($client, 'Test manual');

        expect($result['success'])->toBeTrue();
        expect($result['whitelisted'] ?? false)->toBeTrue();
        expect(strtoupper($client->fresh()->service_status))->not->toBe('SUSPENDED');
    });
});

/* ────────────────────────────────────────────────────────────────────────── */
/*  2) Auditoría                                                             */
/* ────────────────────────────────────────────────────────────────────────── */

describe('Auditoría de operaciones sobre la lista blanca', function () {

    it('registra WHITELIST_ADD al incluir un cliente', function () {
        $admin  = makeSuperAdmin();
        $client = Client::factory()->active()->create();

        app(ClientWhitelistService::class)->addClient(
            client: $client,
            authorizedBy: $admin,
            reason: 'Cliente VIP',
            ipAddress: '10.0.0.5',
        );

        $audit = Audit::where('table_name', 'client_whitelists')
            ->where('operation', 'WHITELIST_ADD')
            ->latest()
            ->first();

        expect($audit)->not->toBeNull();
        expect($audit->user_id)->toBe($admin->id);
        expect($audit->user_type)->toBe(Employee::class);
        expect($audit->ip_address)->toBe('10.0.0.5');
        expect($audit->new_values['client_id'])->toBe($client->id);
        expect($audit->new_values['reason'])->toBe('Cliente VIP');
    });

    it('registra WHITELIST_REMOVE al retirar un cliente', function () {
        $admin  = makeSuperAdmin();
        $client = Client::factory()->active()->create();

        app(ClientWhitelistService::class)->addClient(
            client: $client,
            authorizedBy: $admin,
            reason: 'Cliente VIP',
        );

        app(ClientWhitelistService::class)->removeClient(
            client: $client,
            authorizedBy: $admin,
            reason: 'Vencimiento del acuerdo',
            ipAddress: '10.0.0.10',
        );

        $audit = Audit::where('table_name', 'client_whitelists')
            ->where('operation', 'WHITELIST_REMOVE')
            ->latest()
            ->first();

        expect($audit)->not->toBeNull();
        expect($audit->user_id)->toBe($admin->id);
        expect($audit->old_values['active'])->toBeTrue();
        expect($audit->new_values['active'])->toBeFalse();
    });

    it('registra SUSPEND_BLOCKED_WHITELIST cuando la suspensión es bloqueada', function () {
        $admin  = makeSuperAdmin();
        $client = Client::factory()->active()->create();

        app(ClientWhitelistService::class)->addClient(
            client: $client,
            authorizedBy: $admin,
            reason: 'Pago acordado',
        );

        app(ClientSuspensionService::class)->suspendClient($client, 'Test invoice', 999);

        $audit = Audit::where('table_name', 'clients')
            ->where('operation', 'SUSPEND_BLOCKED_WHITELIST')
            ->where('record_id', (string) $client->id)
            ->latest()
            ->first();

        expect($audit)->not->toBeNull();
        expect($audit->new_values['invoice_id'])->toBe(999);
        expect($audit->new_values['whitelist_id'])->not->toBeNull();
    });
});

/* ────────────────────────────────────────────────────────────────────────── */
/*  3) Permisos (HTTP)                                                       */
/* ────────────────────────────────────────────────────────────────────────── */

describe('Control de acceso a la API de lista blanca', function () {

    it('rechaza peticiones sin autenticación', function () {
        $this->getJson('/api/admin/whitelist')->assertStatus(401);
    });

    it('rechaza a un empleado SIN rol super_admin', function () {
        $employee = makeRegularEmployee();

        $this->actingAs($employee, 'sanctum')
            ->getJson('/api/admin/whitelist')
            ->assertStatus(403);
    });

    it('rechaza POST de un empleado sin permisos', function () {
        $employee = makeRegularEmployee();
        $client   = Client::factory()->active()->create();

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/admin/whitelist', [
                'client_id' => $client->id,
                'reason'    => 'intento no autorizado',
            ])
            ->assertStatus(403);
    });

    it('permite la operación cuando el empleado es super_admin', function () {
        $admin  = makeSuperAdmin();
        $client = Client::factory()->active()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/whitelist', [
                'client_id' => $client->id,
                'reason'    => 'Cliente estratégico — autorizado por gerencia',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.client_id', $client->id);
    });
});

/* ────────────────────────────────────────────────────────────────────────── */
/*  4) Validaciones de la API                                                */
/* ────────────────────────────────────────────────────────────────────────── */

describe('Validaciones de la API', function () {

    it('rechaza un motivo demasiado corto', function () {
        $admin  = makeSuperAdmin();
        $client = Client::factory()->active()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/whitelist', [
                'client_id' => $client->id,
                'reason'    => 'X',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    });

    it('rechaza un client_id inexistente', function () {
        $admin = makeSuperAdmin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/whitelist', [
                'client_id' => 999999,
                'reason'    => 'motivo válido pero cliente inexistente',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_id']);
    });

    it('devuelve 409 si el cliente ya está en la lista', function () {
        $admin  = makeSuperAdmin();
        $client = Client::factory()->active()->create();

        $payload = [
            'client_id' => $client->id,
            'reason'    => 'Inclusión inicial — cliente estratégico',
        ];

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/whitelist', $payload)->assertStatus(201);
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/whitelist', $payload)->assertStatus(409);
    });
});

/* ────────────────────────────────────────────────────────────────────────── */
/*  5) Listado, baja, historial y export                                     */
/* ────────────────────────────────────────────────────────────────────────── */

describe('Listado, baja, historial y export CSV', function () {

    it('lista solo inclusiones activas por defecto', function () {
        $admin    = makeSuperAdmin();
        $clientA  = Client::factory()->active()->create();
        $clientB  = Client::factory()->active()->create();

        $entryA = app(ClientWhitelistService::class)->addClient($clientA, $admin, 'Cliente A activo');
        app(ClientWhitelistService::class)->addClient($clientB, $admin, 'Cliente B activo');
        app(ClientWhitelistService::class)->removeClient($clientA, $admin);

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/whitelist?status=active');
        $res->assertStatus(200);
        $ids = collect($res->json('data'))->pluck('client_id')->all();

        expect($ids)->toContain($clientB->id)
            ->and($ids)->not->toContain($clientA->id);
    });

    it('retira un cliente vía DELETE y vuelve a ser suspendible', function () {
        $admin  = makeSuperAdmin();
        $client = Client::factory()->active()->create();

        $entry = app(ClientWhitelistService::class)->addClient($client, $admin, 'Inclusión temporal');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/whitelist/{$entry->id}", ['reason' => 'fin del acuerdo'])
            ->assertStatus(200)
            ->assertJsonPath('data.active', false);

        expect($client->fresh()->isWhitelisted())->toBeFalse();
    });

    it('devuelve historial filtrado por cliente', function () {
        $admin  = makeSuperAdmin();
        $client = Client::factory()->active()->create();

        $entry = app(ClientWhitelistService::class)->addClient($client, $admin, 'Inicial');
        app(ClientWhitelistService::class)->removeClient($client, $admin);

        $res = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/whitelist/history?client_id={$client->id}")
            ->assertStatus(200);

        $ops = collect($res->json('data'))->pluck('operation')->all();
        expect($ops)->toContain('WHITELIST_ADD')
            ->and($ops)->toContain('WHITELIST_REMOVE');
    });

    it('exporta CSV con cabecera y al menos una fila de datos', function () {
        $admin  = makeSuperAdmin();
        $client = Client::factory()->active()->create();
        app(ClientWhitelistService::class)->addClient($client, $admin, 'Cliente para export CSV');

        $res = $this->actingAs($admin, 'sanctum')->get('/api/admin/whitelist/export?status=active');
        $res->assertStatus(200);

        $content = $res->streamedContent();
        expect($content)->toContain('Cliente ID')
            ->and($content)->toContain((string) $client->id)
            ->and($content)->toContain('Cliente para export CSV');
    });
});
