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

    it('genera todos los ciclos retroactivos desde contract_date hasta hoy para un cliente sin facturas previas', function () {
        seedBillingConfig();
        Carbon::setTestNow(Carbon::parse('2026-05-20'));

        // contract = 2026-02-15 → ciclos: feb 15, mar 15, abr 15, may 15 = 4 ciclos
        [$client, $plan, $cp] = makeClientWithContractDate('2026-02-15', 100);

        $res = $this->actingAs(makeAuthorizedEmployee(), 'sanctum')
            ->postJson('/api/admin/invoices/generate-by-contract');

        $res->assertStatus(200)
            ->assertJsonStructure(['message', 'count', 'report' => [
                'execution_id', 'clients_total', 'generated_count', 'skipped_count', 'errors_count',
                'processed_clients', 'generated', 'skipped', 'errors',
            ]]);

        expect($res->json('count'))->toBe(4);
        expect(Invoice::count())->toBe(4);

        $invoices = Invoice::orderBy('issue_date')->get();
        expect($invoices->pluck('issue_date')->map->toDateString()->all())->toBe([
            '2026-02-15', '2026-03-15', '2026-04-15', '2026-05-15',
        ]);

        $first = $invoices->first();
        expect($first->client_id)->toBe($client->id);
        expect($first->client_plan_id)->toBe($cp->id);
        expect((float) $first->amount)->toBe(100.0);
        expect((float) $first->tax_amount)->toBe(15.0);
        expect((float) $first->total_amount)->toBe(115.0);
        expect($first->metadata['billing_cycle'])->toBe('contract_based');
        expect($first->metadata['contract_date'])->toBe('2026-02-15');
        expect($first->metadata['cycle_start'])->toBe('2026-02-15');

        Carbon::setTestNow();
    });

    it('si el aniversario aún no ocurrió este mes, no genera el ciclo del mes corriente', function () {
        seedBillingConfig();
        Carbon::setTestNow(Carbon::parse('2026-05-10'));

        // contract = 2026-02-15, hoy = mayo 10 (antes del aniversario de mayo)
        // Ciclos generados: feb 15, mar 15, abr 15 = 3 (mayo NO porque 15 > hoy)
        [, , $cp] = makeClientWithContractDate('2026-02-15', 80);

        $this->actingAs(makeAuthorizedEmployee(), 'sanctum')
            ->postJson('/api/admin/invoices/generate-by-contract')
            ->assertStatus(200);

        expect(Invoice::count())->toBe(3);

        $lastInvoice = Invoice::orderBy('issue_date', 'desc')->first();
        expect($lastInvoice->issue_date->toDateString())->toBe('2026-04-15');
        expect($lastInvoice->client_plan_id)->toBe($cp->id);

        Carbon::setTestNow();
    });

    it('no genera duplicados al ejecutar dos veces (todos los ciclos quedan omitidos en la segunda corrida)', function () {
        seedBillingConfig();
        Carbon::setTestNow(Carbon::parse('2026-05-20'));

        // contract = 2026-04-15 → ciclos: abr 15, may 15 = 2
        makeClientWithContractDate('2026-04-15', 100);

        $employee = makeAuthorizedEmployee();

        $r1 = $this->actingAs($employee, 'sanctum')
            ->postJson('/api/admin/invoices/generate-by-contract');
        $r1->assertStatus(200);
        expect($r1->json('count'))->toBe(2);

        $r2 = $this->actingAs($employee, 'sanctum')
            ->postJson('/api/admin/invoices/generate-by-contract');
        $r2->assertStatus(200);
        expect($r2->json('count'))->toBe(0);
        expect($r2->json('report.skipped_count'))->toBe(2);
        expect(Invoice::count())->toBe(2);

        $skipped = $r2->json('report.skipped.0');
        expect($skipped['reason'])->toContain('ya existe');

        Carbon::setTestNow();
    });

    it('no sobrescribe una factura existente con monto distinto y omite solo el ciclo correspondiente', function () {
        seedBillingConfig();
        Carbon::setTestNow(Carbon::parse('2026-05-20'));

        // contract = 2026-04-15 → ciclos: abr 15, may 15
        [$client, , $cp] = makeClientWithContractDate('2026-04-15', 100);

        // Factura preexistente para el ciclo de mayo (issue_date dentro de [may 15, jun 14])
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

        $res = $this->actingAs(makeAuthorizedEmployee(), 'sanctum')
            ->postJson('/api/admin/invoices/generate-by-contract');

        $res->assertStatus(200);
        // El ciclo de abril se genera (1), el de mayo se omite por el preexistente
        expect($res->json('count'))->toBe(1);
        expect($res->json('report.skipped_count'))->toBe(1);

        $preexisting->refresh();
        expect((float) $preexisting->amount)->toBe(999.99);
        expect((float) $preexisting->total_amount)->toBe(1149.99);
        expect(Invoice::count())->toBe(2); // 1 preexistente + 1 nueva (abril)

        Carbon::setTestNow();
    });

    it('procesa múltiples clientes generando todos sus ciclos retroactivos', function () {
        seedBillingConfig();
        Carbon::setTestNow(Carbon::parse('2026-05-20'));

        // Cliente A — contract 2026-03-10 → 3 ciclos (mar, abr, may)
        [$a] = makeClientWithContractDate('2026-03-10', 50);
        // Cliente B — contract 2026-04-12 → 2 ciclos (abr, may)
        [$b] = makeClientWithContractDate('2026-04-12', 75);
        // Cliente C — contract 2026-05-15, con factura preexistente del ciclo de mayo
        [$c, , $cpC] = makeClientWithContractDate('2026-05-15', 100);
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
        // A: 3 generadas, B: 2 generadas, C: 0 generadas (1 omitida)
        expect($res->json('report.generated_count'))->toBe(5);
        expect($res->json('report.skipped_count'))->toBe(1);
        expect($res->json('report.clients_total'))->toBe(3);
        expect(Invoice::count())->toBe(6); // 5 nuevas + 1 preexistente

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

        // contract = 2026-05-31 → ciclos: may 31, jun 30 (clamped, jun no tiene 31)
        makeClientWithContractDate('2026-05-31', 60);

        $this->actingAs(makeAuthorizedEmployee(), 'sanctum')
            ->postJson('/api/admin/invoices/generate-by-contract')
            ->assertStatus(200);

        expect(Invoice::count())->toBe(2);

        $issueDates = Invoice::orderBy('issue_date')->pluck('issue_date')->map->toDateString()->all();
        expect($issueDates)->toBe(['2026-05-31', '2026-06-30']);

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
        $clients = 60;

        // Cada cliente tiene contract_date entre el día 1 y 20 de abril 2026.
        // Con today=may 20: cada cliente tiene exactamente 2 ciclos (abr y may).
        for ($i = 1; $i <= $clients; $i++) {
            $client = Client::factory()->create([
                'contract_date' => '2026-04-' . str_pad((string) (($i % 20) + 1), 2, '0', STR_PAD_LEFT),
            ]);
            ClientPlan::factory()->create([
                'client_id'     => $client->id,
                'plan_id'       => $plan->id,
                'status'        => 'active',
                'current_price' => 50,
            ]);
        }

        $expectedInvoices = $clients * 2;

        $started = microtime(true);
        $res = $this->actingAs(makeAuthorizedEmployee(), 'sanctum')
            ->postJson('/api/admin/invoices/generate-by-contract');
        $elapsed = microtime(true) - $started;

        $res->assertStatus(200);
        expect($res->json('report.generated_count'))->toBe($expectedInvoices);
        expect(Invoice::count())->toBe($expectedInvoices);

        // Segunda ejecución: ninguna nueva, todas omitidas
        $res2 = $this->actingAs(makeAuthorizedEmployee(), 'sanctum')
            ->postJson('/api/admin/invoices/generate-by-contract');
        $res2->assertStatus(200);
        expect($res2->json('report.generated_count'))->toBe(0);
        expect($res2->json('report.skipped_count'))->toBe($expectedInvoices);
        expect(Invoice::count())->toBe($expectedInvoices);

        // Aserción suelta de rendimiento — no debe demorar irrazonablemente
        expect($elapsed)->toBeLessThan(30.0);

        Carbon::setTestNow();
    });

});
