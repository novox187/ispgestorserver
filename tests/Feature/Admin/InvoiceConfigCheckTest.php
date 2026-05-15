<?php

use App\Models\Employee;
use App\Models\Setting;
use App\Models\Client;
use App\Models\Plan;
use App\Models\ClientPlan;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Shared helpers ─────────────────────────────────────────────────────────

function seedFullConfig(): void
{
    $rows = [
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_name',            'value' => 'Iron Link S.A.S.', 'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_ruc',             'value' => '0100000000001',    'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_address',         'value' => 'Calle 1',          'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_city',            'value' => 'Quito',            'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_country',         'value' => 'Ecuador',          'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_email',           'value' => 'a@ironlink.com',   'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'tax',      'key' => 'tax_rate',               'value' => '0.15',             'data_type' => 'float',  'is_public' => true],
        ['module' => 'facturacion', 'group' => 'tax',      'key' => 'tax_name',               'value' => 'IVA',              'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'currency', 'key' => 'currency_code',          'value' => 'USD',              'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'currency', 'key' => 'currency_symbol',        'value' => '$',                'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'legal',    'key' => 'sri_establishment_code', 'value' => '001',              'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'legal',    'key' => 'sri_emission_point',     'value' => '001',              'data_type' => 'string', 'is_public' => true],
    ];
    foreach ($rows as $row) {
        Setting::create(array_merge($row, ['description' => '']));
    }
}

function makeEmployee(): Employee
{
    return Employee::factory()->create();
}

// ── GET /admin/invoices/config-check ──────────────────────────────────────

describe('GET /admin/invoices/config-check', function () {

    it('devuelve 200 valid:true cuando la configuración está completa', function () {
        seedFullConfig();

        $this->actingAs(makeEmployee(), 'sanctum')
            ->getJson('/api/admin/invoices/config-check')
            ->assertStatus(200)
            ->assertJson(['valid' => true]);
    });

    it('devuelve 422 valid:false con missing cuando faltan claves', function () {
        // No seeds — empty config

        $res = $this->actingAs(makeEmployee(), 'sanctum')
            ->getJson('/api/admin/invoices/config-check');

        $res->assertStatus(422)
            ->assertJson(['valid' => false])
            ->assertJsonStructure(['valid', 'missing', 'invalid', 'messages']);

        expect($res->json('missing'))->not->toBeEmpty();
        expect($res->json('messages'))->not->toBeEmpty();
    });

    it('devuelve 422 con clave inválida (tax_rate fuera de rango)', function () {
        seedFullConfig();
        Setting::where('key', 'tax_rate')->update(['value' => '2.5']);

        $res = $this->actingAs(makeEmployee(), 'sanctum')
            ->getJson('/api/admin/invoices/config-check');

        $res->assertStatus(422)
            ->assertJson(['valid' => false]);

        expect($res->json('invalid.tax.tax_rate'))->not->toBeNull();
    });

    it('requiere autenticación', function () {
        $this->getJson('/api/admin/invoices/config-check')
            ->assertStatus(401);
    });

});

// ── POST /admin/invoices/generate-auto ───────────────────────────────────

describe('POST /admin/invoices/generate-auto', function () {

    it('bloquea la generación automática cuando la configuración está incompleta', function () {
        // No config seeded
        $employee = makeEmployee();
        Client::factory()->has(
            ClientPlan::factory()->for(Plan::factory()->create(['monthly_price' => 50]))->state(['status' => 'active'])
        )->create();

        $res = $this->actingAs($employee, 'sanctum')
            ->postJson('/api/admin/invoices/generate-auto');

        $res->assertStatus(422)
            ->assertJson(['valid' => false])
            ->assertJsonPath('config_url', '/configuraciones/facturacion');

        expect(Invoice::count())->toBe(0);
    });

    it('permite la generación automática con configuración completa', function () {
        seedFullConfig();
        $employee = makeEmployee();
        $client   = Client::factory()->create();
        $plan     = Plan::factory()->create(['monthly_price' => 50, 'download_speed' => 10, 'upload_speed' => 5]);
        ClientPlan::factory()->create([
            'client_id' => $client->id,
            'plan_id'   => $plan->id,
            'status'    => 'active',
            'current_price' => 50,
        ]);

        $res = $this->actingAs($employee, 'sanctum')
            ->postJson('/api/admin/invoices/generate-auto');

        $res->assertStatus(200)
            ->assertJsonStructure(['message', 'count', 'invoices']);

        expect(Invoice::count())->toBeGreaterThan(0);

        $invoice = Invoice::first();
        expect($invoice->configuration_snapshot)->not->toBeNull();
        expect($invoice->configuration_snapshot)->toHaveKey('tax_rate');
    });

});

// ── POST /admin/invoices (store manual) ──────────────────────────────────

describe('POST /admin/invoices', function () {

    it('bloquea creación manual cuando la configuración está incompleta', function () {
        $employee   = makeEmployee();
        $client     = Client::factory()->create();
        $plan       = Plan::factory()->create(['monthly_price' => 100]);
        $clientPlan = ClientPlan::factory()->create([
            'client_id' => $client->id,
            'plan_id'   => $plan->id,
            'status'    => 'active',
        ]);

        $res = $this->actingAs($employee, 'sanctum')
            ->postJson('/api/admin/invoices', [
                'client_id'      => $client->id,
                'client_plan_id' => $clientPlan->id,
                'issue_date'     => now()->toDateString(),
                'due_date'       => now()->addDays(15)->toDateString(),
                'amount'         => 100,
                'status'         => 'pending',
            ]);

        $res->assertStatus(422)
            ->assertJsonPath('config_url', '/configuraciones/facturacion');

        expect(Invoice::count())->toBe(0);
    });

    it('crea factura con totales derivados del snapshot cuando la configuración está completa', function () {
        seedFullConfig();
        $employee   = makeEmployee();
        $client     = Client::factory()->create();
        $plan       = Plan::factory()->create(['monthly_price' => 100]);
        $clientPlan = ClientPlan::factory()->create([
            'client_id' => $client->id,
            'plan_id'   => $plan->id,
            'status'    => 'active',
        ]);

        $res = $this->actingAs($employee, 'sanctum')
            ->postJson('/api/admin/invoices', [
                'client_id'      => $client->id,
                'client_plan_id' => $clientPlan->id,
                'issue_date'     => now()->toDateString(),
                'due_date'       => now()->addDays(15)->toDateString(),
                'amount'         => 100,
                'status'         => 'pending',
            ]);

        $res->assertStatus(201);

        $invoice = Invoice::first();

        // tax_amount derived from snapshot tax_rate (0.15), not from request
        expect((float) $invoice->tax_amount)->toBe(15.0);
        expect((float) $invoice->total_amount)->toBe(115.0);

        // Snapshot frozen and contains required keys
        expect($invoice->configuration_snapshot)->toHaveKey('issuer_name');
        expect($invoice->configuration_snapshot)->toHaveKey('tax_rate');
        expect($invoice->configuration_snapshot['tax_rate']['_public'])->toBeTrue();
    });

    it('el snapshot contiene _public correcto para claves privadas', function () {
        seedFullConfig();
        // Add a private billing key
        Setting::create([
            'module' => 'facturacion', 'group' => 'billing',
            'key' => 'grace_period_days', 'value' => '3',
            'data_type' => 'integer', 'is_public' => false, 'description' => '',
        ]);

        $employee   = makeEmployee();
        $client     = Client::factory()->create();
        $plan       = Plan::factory()->create(['monthly_price' => 100]);
        $clientPlan = ClientPlan::factory()->create([
            'client_id' => $client->id, 'plan_id' => $plan->id, 'status' => 'active',
        ]);

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/admin/invoices', [
                'client_id' => $client->id, 'client_plan_id' => $clientPlan->id,
                'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(15)->toDateString(),
                'amount' => 100, 'status' => 'pending',
            ])->assertStatus(201);

        $invoice = Invoice::first();
        expect($invoice->configuration_snapshot['grace_period_days']['_public'])->toBeFalse();
        expect($invoice->configuration_snapshot['issuer_name']['_public'])->toBeTrue();
    });

});

// ── Redirección: config_url presente en respuestas de error ──────────────

describe('config_url en respuestas de error', function () {

    it('config-check NO incluye config_url en la respuesta (lo maneja el frontend)', function () {
        $res = $this->actingAs(makeEmployee(), 'sanctum')
            ->getJson('/api/admin/invoices/config-check');

        $res->assertStatus(422);
        // config_url is intentionally absent from config-check — present only in store/generate-auto
        $data = $res->json();
        expect(array_key_exists('config_url', $data))->toBeFalse();
    });

    it('generate-auto incluye config_url cuando bloquea', function () {
        $this->actingAs(makeEmployee(), 'sanctum')
            ->postJson('/api/admin/invoices/generate-auto')
            ->assertStatus(422)
            ->assertJsonPath('config_url', '/configuraciones/facturacion');
    });

    it('store incluye config_url cuando bloquea', function () {
        $client     = Client::factory()->create();
        $plan       = Plan::factory()->create(['monthly_price' => 100]);
        $clientPlan = ClientPlan::factory()->create([
            'client_id' => $client->id, 'plan_id' => $plan->id, 'status' => 'active',
        ]);

        $this->actingAs(makeEmployee(), 'sanctum')
            ->postJson('/api/admin/invoices', [
                'client_id' => $client->id, 'client_plan_id' => $clientPlan->id,
                'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(15)->toDateString(),
                'amount' => 100, 'status' => 'pending',
            ])
            ->assertStatus(422)
            ->assertJsonPath('config_url', '/configuraciones/facturacion');
    });

});
