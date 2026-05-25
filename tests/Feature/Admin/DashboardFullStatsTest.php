<?php

use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\Invoice;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Crea una factura en el estado indicado, generando un cliente y plan mínimos
 * sin tocar la lógica de facturación automática.
 */
function makeInvoiceForStatus(string $status, float $amount = 100.00): Invoice
{
    static $seq = 0;
    $seq++;

    $client = Client::factory()->create();
    $plan   = Plan::factory()->create([
        'name'                => "Plan Test {$seq}",
        'slug'                => "plan-test-{$seq}",
        'mikrotik_queue_name' => "Plan_Test_{$seq}",
        'monthly_price'       => $amount,
    ]);
    $cp = ClientPlan::factory()->create([
        'client_id'         => $client->id,
        'plan_id'           => $plan->id,
        'status'            => 'active',
        'next_billing_date' => now()->addMonth(),
        'billing_cycle'     => 'monthly',
        'current_price'     => $amount,
    ]);

    return Invoice::create([
        'client_id'      => $client->id,
        'client_plan_id' => $cp->id,
        'invoice_number' => Invoice::generateInvoiceNumber(),
        'issue_date'     => now()->toDateString(),
        'due_date'       => now()->addDays(10)->toDateString(),
        'amount'         => $amount,
        'tax_amount'     => 0,
        'total_amount'   => $amount,
        'status'         => $status,
    ]);
}

it('contabiliza facturas fallidas dentro de las pendientes en /dashboard/full-stats', function () {
    seedValidInvoiceConfig();
    $employee = makeSuperAdminEmployee();

    // 2 pendientes ($100 c/u), 1 fallida ($150), 1 pagada y 1 cancelada (estas dos no deben contar).
    makeInvoiceForStatus(Invoice::STATUS_PENDING,   100);
    makeInvoiceForStatus(Invoice::STATUS_PENDING,   100);
    makeInvoiceForStatus(Invoice::STATUS_FAILED,    150);
    makeInvoiceForStatus(Invoice::STATUS_PAID,      999);
    makeInvoiceForStatus(Invoice::STATUS_CANCELLED, 888);

    $response = $this->actingAs($employee, 'sanctum')
        ->getJson('/api/admin/dashboard/full-stats');

    $response->assertOk()
        ->assertJsonStructure(['invoices_summary' => ['pending_count', 'pending_amount']]);

    expect($response->json('invoices_summary.pending_count'))->toBe(3);
    expect((float) $response->json('invoices_summary.pending_amount'))->toBe(350.0);
});

it('actualiza el conteo de pendientes al agregar una nueva factura fallida', function () {
    seedValidInvoiceConfig();
    $employee = makeSuperAdminEmployee();

    makeInvoiceForStatus(Invoice::STATUS_PENDING, 100);

    $before = $this->actingAs($employee, 'sanctum')
        ->getJson('/api/admin/dashboard/full-stats');

    expect($before->json('invoices_summary.pending_count'))->toBe(1);
    expect((float) $before->json('invoices_summary.pending_amount'))->toBe(100.0);

    // Acto: se agrega una factura fallida (cobro automático rechazado por saldo insuficiente).
    makeInvoiceForStatus(Invoice::STATUS_FAILED, 75);

    $after = $this->actingAs($employee, 'sanctum')
        ->getJson('/api/admin/dashboard/full-stats');

    expect($after->json('invoices_summary.pending_count'))->toBe(2);
    expect((float) $after->json('invoices_summary.pending_amount'))->toBe(175.0);
});

it('scope pendingOrFailed devuelve facturas con status pending y failed', function () {
    makeInvoiceForStatus(Invoice::STATUS_PENDING);
    makeInvoiceForStatus(Invoice::STATUS_FAILED);
    makeInvoiceForStatus(Invoice::STATUS_PAID);
    makeInvoiceForStatus(Invoice::STATUS_CANCELLED);
    makeInvoiceForStatus(Invoice::STATUS_DRAFT);

    $statuses = Invoice::pendingOrFailed()->pluck('status')->sort()->values()->all();

    expect($statuses)->toBe([Invoice::STATUS_FAILED, Invoice::STATUS_PENDING]);
});
