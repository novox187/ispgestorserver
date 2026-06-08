<?php

use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\ClientWhitelist;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\AutoBillingService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/**
 * Snapshot de facturación válido sin impuestos para aislar la prueba de la
 * configuración real del módulo.
 */
function fakeInvoiceSettings(): void
{
    test()->mock(SettingService::class, function (MockInterface $m) {
        $m->shouldReceive('buildInvoiceSnapshot')->andReturn([
            'tax_rate'         => ['value' => 0, '_public' => false],
            'invoice_due_days' => ['value' => 15, '_public' => false],
        ]);
        $m->shouldReceive('taxRateFromSnapshot')->andReturn(0.0);
        $m->shouldReceive('invoiceDueDaysFromSnapshot')->andReturn(15);
    });
}

function makeUniquePlan(): Plan
{
    $suffix = uniqid();

    return Plan::factory()->create([
        'name' => "Plan {$suffix}",
        'slug' => "plan-{$suffix}",
    ]);
}

function makeClientWithPlan(string $serviceStatus, string $planStatus, float $price = 30.00): Client
{
    $client = Client::factory()->create(['service_status' => $serviceStatus]);
    $plan   = makeUniquePlan();

    ClientPlan::create([
        'client_id'         => $client->id,
        'plan_id'           => $plan->id,
        'start_date'        => now()->subMonths(2)->toDateString(),
        'billing_cycle'     => 'monthly',
        'status'            => $planStatus,
        'next_billing_date' => now()->addMonth()->toDateString(),
        'current_price'     => $price,
    ]);

    return $client;
}

function whitelistClient(Client $client): void
{
    ClientWhitelist::create([
        'client_id'  => $client->id,
        'added_at'   => now(),
        'reason'     => 'cliente protegido en pruebas',
        'expires_at' => null,
        'active'     => true,
    ]);
}

describe('AutoBillingService::generateMonthlyInvoices() — lista blanca', function () {

    it('genera factura para un cliente en lista blanca cuyo plan está SUSPENDIDO', function () {
        fakeInvoiceSettings();

        $client = makeClientWithPlan('SUSPENDED', 'suspended');
        whitelistClient($client);

        $invoices = app(AutoBillingService::class)->generateMonthlyInvoices();

        expect($invoices)->toHaveCount(1);
        expect(Invoice::where('client_id', $client->id)->count())->toBe(1);
    });

    it('NO genera factura para un cliente SUSPENDIDO que no está en lista blanca', function () {
        fakeInvoiceSettings();

        $client = makeClientWithPlan('SUSPENDED', 'suspended');

        $invoices = app(AutoBillingService::class)->generateMonthlyInvoices();

        expect($invoices)->toHaveCount(0);
        expect(Invoice::where('client_id', $client->id)->count())->toBe(0);
    });

    it('sigue generando factura para un cliente ACTIVO normal', function () {
        fakeInvoiceSettings();

        $client = makeClientWithPlan('ACTIVE', 'active');

        $invoices = app(AutoBillingService::class)->generateMonthlyInvoices();

        expect($invoices)->toHaveCount(1);
        expect(Invoice::where('client_id', $client->id)->count())->toBe(1);
    });

    it('no factura planes suspendidos de clientes en lista blanca cuya inclusión expiró', function () {
        fakeInvoiceSettings();

        $client = makeClientWithPlan('SUSPENDED', 'suspended');

        // Inclusión vencida: ya no protege ni habilita la facturación.
        ClientWhitelist::create([
            'client_id'  => $client->id,
            'added_at'   => now()->subMonths(2),
            'reason'     => 'inclusión vencida',
            'expires_at' => now()->subDay(),
            'active'     => true,
        ]);

        $invoices = app(AutoBillingService::class)->generateMonthlyInvoices();

        expect($invoices)->toHaveCount(0);
    });

    it('factura el plan activo pero no el cancelado de un mismo cliente', function () {
        fakeInvoiceSettings();

        $client = makeClientWithPlan('ACTIVE', 'active');
        $plan   = makeUniquePlan();

        // Segundo plan cancelado: no debe facturarse.
        ClientPlan::create([
            'client_id'         => $client->id,
            'plan_id'           => $plan->id,
            'start_date'        => now()->subMonths(6)->toDateString(),
            'billing_cycle'     => 'monthly',
            'status'            => 'cancelled',
            'next_billing_date' => now()->subMonth()->toDateString(),
            'current_price'     => 99.00,
        ]);

        $invoices = app(AutoBillingService::class)->generateMonthlyInvoices();

        expect($invoices)->toHaveCount(1);
        expect((float) $invoices[0]->amount)->toBe(30.00);
    });
});
