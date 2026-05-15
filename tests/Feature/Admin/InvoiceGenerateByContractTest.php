<?php

use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function seedBillingConfig(): void
{
    $rows = [
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_name',            'value' => 'Iron Link S.A.S.',  'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_ruc',             'value' => '0100000000001',     'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_address',         'value' => 'Calle 1',           'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_city',            'value' => 'Quito',             'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_country',         'value' => 'Ecuador',           'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_email',           'value' => 'a@ironlink.com',    'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'tax',      'key' => 'tax_rate',               'value' => '0.15',              'data_type' => 'float',  'is_public' => true],
        ['module' => 'facturacion', 'group' => 'tax',      'key' => 'tax_name',               'value' => 'IVA',               'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'currency', 'key' => 'currency_code',          'value' => 'USD',               'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'currency', 'key' => 'currency_symbol',        'value' => '$',                 'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'legal',    'key' => 'sri_establishment_code', 'value' => '001',               'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'legal',    'key' => 'sri_emission_point',     'value' => '001',               'data_type' => 'string', 'is_public' => true],
    ];
    foreach ($rows as $row) {
        Setting::create(array_merge($row, ['description' => '']));
    }
}

function makeAuthorizedEmployee(): Employee
{
    $role = Role::firstOrCreate(
        ['slug' => 'super_admin'],
        ['nombre' => 'Super Admin', 'descripcion' => '']
    );
    return Employee::factory()->create(['role_id' => $role->id]);
}

function makeClientWithContractDate(string $contractDate, float $price = 100): array
{
    static $seq = 0;
    $seq++;
    $client = Client::factory()->create(['contract_date' => $contractDate]);
    $plan   = Plan::factory()->create([
        'monthly_price' => $price,
        'name'          => "Plan Test {$seq}",
        'slug'          => "plan-test-{$seq}",
        'mikrotik_queue_name' => "Plan_Test_{$seq}",
    ]);
    $cp     = ClientPlan::factory()->create([
        'client_id'     => $client->id,
        'plan_id'       => $plan->id,
        'status'        => 'active',
        'current_price' => $price,
    ]);

    return [$client, $plan, $cp];
}

describe('POST /admin/invoices/generate-by-contract', function () {

    it('bloquea con 422 cuando la configuración está incompleta', function () {
        [$client] = makeClientWithContractDate('2024-01-15');

        $this->actingAs(makeAuthorizedEmployee(), 'sanctum')
            ->postJson('/api/admin/invoices/generate-by-contract')
            ->assertStatus(422)
            ->assertJson(['valid' => false])
            ->assertJsonPath('config_url', '/configuraciones/facturacion');

        expect(Invoice::count())->toBe(0);
    });

    it('genera una factura para un cliente sin facturas previas, anclada al día del contrato', function () {
        seedBillingConfig();
        Carbon::setTestNow(Carbon::parse('2026-05-20'));

        [$client, $plan, $cp] = makeClientWithContractDate('2024-01-15', 100);

        $res = $this->actingAs(makeAuthorizedEmployee(), 'sanctum')
            ->postJson('/api/admin/invoices/generate-by-contract');

        $res->assertStatus(200)
            ->assertJsonStructure(['message', 'count', 'report' => [
                'execution_id', 'clients_total', 'generated_count', 'skipped_count', 'errors_count',
                'processed_clients', 'generated', 'skipped', 'errors',
            ]]);

        expect($res->json('count'))->toBe(1);
        expect(Invoice::count())->toBe(1);

        $invoice = Invoice::first();
        expect($invoice->client_id)->toBe($client->id);
        expect($invoice->client_plan_id)->toBe($cp->id);
        expect($invoice->issue_date->toDateString())->toBe('2026-05-15');
        expect((float) $invoice->amount)->toBe(100.0);
        expect((float) $invoice->tax_amount)->toBe(15.0);
        expect((float) $invoice->total_amount)->toBe(115.0);
        expect($invoice->metadata['billing_cycle'])->toBe('contract_based');
        expect($invoice->metadata['contract_date'])->toBe('2024-01-15');
        expect($invoice->metadata['cycle_start'])->toBe('2026-05-15');

        Carbon::setTestNow();
    });

    it('si el aniversario aún no ocurrió este mes, ancla al aniversario del mes anterior', function () {
        seedBillingConfig();
        Carbon::setTestNow(Carbon::parse('2026-05-10'));

        [, , $cp] = makeClientWithContractDate('2024-01-15', 80);

        $this->actingAs(makeAuthorizedEmployee(), 'sanctum')
            ->postJson('/api/admin/invoices/generate-by-contract')
            ->assertStatus(200);

        $invoice = Invoice::first();
        expect($invoice->issue_date->toDateString())->toBe('2026-04-15');
        expect($invoice->client_plan_id)->toBe($cp->id);

        Carbon::setTestNow();
    });

    it('no genera duplicados al ejecutar dos veces sobre el mismo ciclo', function () {
        seedBillingConfig();
        Carbon::setTestNow(Carbon::parse('2026-05-20'));

        makeClientWithContractDate('2024-01-15', 100);

        $employee = makeAuthorizedEmployee();

        $r1 = $this->actingAs($employee, 'sanctum')
            ->postJson('/api/admin/invoices/generate-by-contract');
        $r1->assertStatus(200);
        expect($r1->json('count'))->toBe(1);

        $r2 = $this->actingAs($employee, 'sanctum')
            ->postJson('/api/admin/invoices/generate-by-contract');
        $r2->assertStatus(200);
        expect($r2->json('count'))->toBe(0);
        expect($r2->json('report.skipped_count'))->toBe(1);
        expect(Invoice::count())->toBe(1);

        $skipped = $r2->json('report.skipped.0');
        expect($skipped['reason'])->toContain('ya existe');

        Carbon::setTestNow();
    });

    it('no sobrescribe una factura existente con monto distinto', function () {
        seedBillingConfig();
        Carbon::setTestNow(Carbon::parse('2026-05-20'));

        [$client, , $cp] = makeClientWithContractDate('2024-01-15', 100);

        $preexisting = Invoice::create([
            'client_id'              => $client->id,
            'client_plan_id'         => $cp->id,
            'invoice_number'         => '001-001-000099999',
            'issue_date'             => '2026-05-15',
            'due_date'               => '2026-05-30',
            'amount'                 => 999.99,
            'tax_amount'             => 150,
            'total_amount'           => 1149.99,
            'status'                 => Invoice::STATUS_PENDING,
            'configuration_snapshot' => [],
        ]);

        $this->actingAs(makeAuthorizedEmployee(), 'sanctum')
            ->postJson('/api/admin/invoices/generate-by-contract')
            ->assertStatus(200)
            ->assertJsonPath('count', 0);

        $preexisting->refresh();
        expect((float) $preexisting->amount)->toBe(999.99);
        expect((float) $preexisting->total_amount)->toBe(1149.99);
        expect(Invoice::count())->toBe(1);

        Carbon::setTestNow();
    });

    it('procesa múltiples clientes en una sola ejecución', function () {
        seedBillingConfig();
        Carbon::setTestNow(Carbon::parse('2026-05-20'));

        // Cliente A — sin facturas previas: se genera
        [$a] = makeClientWithContractDate('2024-01-10', 50);
        // Cliente B — sin facturas previas: se genera
        [$b] = makeClientWithContractDate('2024-01-12', 75);
        // Cliente C — ya tiene factura del ciclo actual: se omite
        [$c, , $cpC] = makeClientWithContractDate('2024-01-15', 100);
        Invoice::create([
            'client_id'              => $c->id,
            'client_plan_id'         => $cpC->id,
            'invoice_number'         => '001-001-000000777',
            'issue_date'             => '2026-05-15',
            'due_date'               => '2026-05-30',
            'amount'                 => 100,
            'tax_amount'             => 15,
            'total_amount'           => 115,
            'status'                 => Invoice::STATUS_PENDING,
            'configuration_snapshot' => [],
        ]);

        $res = $this->actingAs(makeAuthorizedEmployee(), 'sanctum')
            ->postJson('/api/admin/invoices/generate-by-contract');

        $res->assertStatus(200);
        expect($res->json('report.generated_count'))->toBe(2);
        expect($res->json('report.skipped_count'))->toBe(1);
        expect($res->json('report.clients_total'))->toBe(3);
        expect(Invoice::count())->toBe(3);

        Carbon::setTestNow();
    });

    it('ignora clientes sin contract_date', function () {
        seedBillingConfig();
        Carbon::setTestNow(Carbon::parse('2026-05-20'));

        $client = Client::factory()->create(['contract_date' => null]);
        $plan   = Plan::factory()->create(['monthly_price' => 100]);
        ClientPlan::factory()->create([
            'client_id' => $client->id,
            'plan_id'   => $plan->id,
            'status'    => 'active',
            'current_price' => 100,
        ]);

        $res = $this->actingAs(makeAuthorizedEmployee(), 'sanctum')
            ->postJson('/api/admin/invoices/generate-by-contract');

        $res->assertStatus(200);
        expect($res->json('report.clients_total'))->toBe(0);
        expect(Invoice::count())->toBe(0);

        Carbon::setTestNow();
    });

    it('ignora client_plans no activos', function () {
        seedBillingConfig();
        Carbon::setTestNow(Carbon::parse('2026-05-20'));

        $client = Client::factory()->create(['contract_date' => '2024-01-15']);
        $plan   = Plan::factory()->create(['monthly_price' => 100]);
        ClientPlan::factory()->create([
            'client_id' => $client->id,
            'plan_id'   => $plan->id,
            'status'    => 'cancelled',
            'current_price' => 100,
        ]);

        $res = $this->actingAs(makeAuthorizedEmployee(), 'sanctum')
            ->postJson('/api/admin/invoices/generate-by-contract');

        $res->assertStatus(200);
        expect(Invoice::count())->toBe(0);

        Carbon::setTestNow();
    });

    it('maneja meses cortos sin desbordar (contract_date=31, mes con 30 días)', function () {
        seedBillingConfig();
        Carbon::setTestNow(Carbon::parse('2026-06-30'));

        makeClientWithContractDate('2024-01-31', 60);

        $this->actingAs(makeAuthorizedEmployee(), 'sanctum')
            ->postJson('/api/admin/invoices/generate-by-contract')
            ->assertStatus(200);

        $invoice = Invoice::first();
        // junio tiene 30 días -> aniversario clamped al 30
        expect($invoice->issue_date->toDateString())->toBe('2026-06-30');

        Carbon::setTestNow();
    });

    it('requiere autenticación', function () {
        $this->postJson('/api/admin/invoices/generate-by-contract')
            ->assertStatus(401);
    });

});

describe('regresión POST /admin/invoices/generate-auto', function () {

    it('sigue funcionando: genera factura del mes actual y no se ve afectado por el nuevo endpoint', function () {
        seedBillingConfig();

        [$client, , $cp] = makeClientWithContractDate('2024-01-15', 100);

        $res = $this->actingAs(makeAuthorizedEmployee(), 'sanctum')
            ->postJson('/api/admin/invoices/generate-auto');

        $res->assertStatus(200)
            ->assertJsonStructure(['message', 'count', 'invoices']);

        expect($res->json('count'))->toBe(1);

        $invoice = Invoice::first();
        expect($invoice->client_id)->toBe($client->id);
        expect($invoice->client_plan_id)->toBe($cp->id);
        // El generate-auto original usa now() como issue_date — no usa contract_date
        expect($invoice->metadata['billing_cycle'])->toBe('monthly');
    });

});

describe('rendimiento del endpoint generate-by-contract', function () {

    it('procesa un volumen alto de clientes sin generar duplicados', function () {
        seedBillingConfig();
        Carbon::setTestNow(Carbon::parse('2026-05-20'));

        $plan = Plan::factory()->create(['monthly_price' => 50]);
        $count = 60;

        for ($i = 1; $i <= $count; $i++) {
            $client = Client::factory()->create([
                'contract_date' => '2024-01-' . str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT),
            ]);
            ClientPlan::factory()->create([
                'client_id'     => $client->id,
                'plan_id'       => $plan->id,
                'status'        => 'active',
                'current_price' => 50,
            ]);
        }

        $started = microtime(true);
        $res = $this->actingAs(makeAuthorizedEmployee(), 'sanctum')
            ->postJson('/api/admin/invoices/generate-by-contract');
        $elapsed = microtime(true) - $started;

        $res->assertStatus(200);
        expect($res->json('report.generated_count'))->toBe($count);
        expect(Invoice::count())->toBe($count);

        // Segunda ejecución: ninguna nueva, todas omitidas
        $res2 = $this->actingAs(makeAuthorizedEmployee(), 'sanctum')
            ->postJson('/api/admin/invoices/generate-by-contract');
        $res2->assertStatus(200);
        expect($res2->json('report.generated_count'))->toBe(0);
        expect($res2->json('report.skipped_count'))->toBe($count);
        expect(Invoice::count())->toBe($count);

        // Aserción suelta de rendimiento — no debe demorar irrazonablemente
        expect($elapsed)->toBeLessThan(30.0);

        Carbon::setTestNow();
    });

});
