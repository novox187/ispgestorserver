<?php

use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Crea un cliente con plan activo y, opcionalmente, facturas.
 *
 * @param  array<int, array{status: string, total: float, due: string}>  $invoices
 */
function makeClientWithInvoices(string $name, string $serviceStatus, array $invoices = [], float $balance = 0.0): Client
{
    static $seq = 0;
    $seq++;

    $client = Client::factory()->create([
        'full_name'      => $name,
        'service_status' => $serviceStatus,
    ]);

    Wallet::create(['client_id' => $client->id, 'balance' => $balance]);

    $plan = Plan::factory()->create([
        'name'                => "Plan Deuda {$seq}",
        'slug'                => "plan-deuda-{$seq}",
        'mikrotik_queue_name' => "Plan_Deuda_{$seq}",
        'monthly_price'       => 25.00,
    ]);

    $cp = ClientPlan::factory()->create([
        'client_id'         => $client->id,
        'plan_id'           => $plan->id,
        'status'            => 'active',
        'next_billing_date' => now()->addMonth(),
        'billing_cycle'     => 'monthly',
        'current_price'     => 25.00,
    ]);

    foreach ($invoices as $inv) {
        Invoice::create([
            'client_id'      => $client->id,
            'client_plan_id' => $cp->id,
            'invoice_number' => Invoice::generateInvoiceNumber(),
            'issue_date'     => now()->subDays(20)->toDateString(),
            'due_date'       => $inv['due'],
            'amount'         => $inv['total'],
            'tax_amount'     => 0,
            'total_amount'   => $inv['total'],
            'status'         => $inv['status'],
        ]);
    }

    return $client;
}

function summaryFor(array $params = []): array
{
    $request  = new Illuminate\Http\Request($params);
    $response = app(App\Http\Controllers\Admin\ClientController::class)->listSummary($request);

    return json_decode($response->getContent(), true);
}

it('agrega deuda, vencidas y saldo por cliente en /clientes/summary', function () {
    makeClientWithInvoices('Deudor Vencido', 'active', [
        ['status' => Invoice::STATUS_PENDING, 'total' => 30.00, 'due' => now()->subDays(5)->toDateString()],
        ['status' => Invoice::STATUS_FAILED,  'total' => 20.00, 'due' => now()->addDays(5)->toDateString()],
        ['status' => Invoice::STATUS_PAID,    'total' => 99.00, 'due' => now()->subDays(9)->toDateString()],
    ], balance: 12.50);

    $rows = collect(summaryFor(['per_page' => 50])['data']);
    $row  = $rows->firstWhere('name', 'Deudor Vencido');

    // pending 30 + failed 20; la pagada no cuenta
    expect((float) $row['debt_total'])->toBe(50.0)
        ->and($row['debt_count'])->toBe(2)
        ->and($row['overdue_count'])->toBe(1)
        ->and((float) $row['wallet_balance'])->toBe(12.5);
});

it('deja en cero a los clientes sin facturas pendientes', function () {
    makeClientWithInvoices('Cliente Al Dia', 'active', [
        ['status' => Invoice::STATUS_PAID, 'total' => 40.00, 'due' => now()->subDay()->toDateString()],
    ]);

    $row = collect(summaryFor(['per_page' => 50])['data'])->firstWhere('name', 'Cliente Al Dia');

    expect((float) $row['debt_total'])->toBe(0.0)
        ->and($row['debt_count'])->toBe(0)
        ->and($row['overdue_count'])->toBe(0);
});

it('filtra por with_debt dejando fuera a los clientes al día', function () {
    makeClientWithInvoices('Con Deuda', 'active', [
        ['status' => Invoice::STATUS_PENDING, 'total' => 10.00, 'due' => now()->addDays(3)->toDateString()],
    ]);
    makeClientWithInvoices('Sin Deuda', 'active', [
        ['status' => Invoice::STATUS_PAID, 'total' => 10.00, 'due' => now()->subDays(3)->toDateString()],
    ]);

    $names = collect(summaryFor(['per_page' => 50, 'status' => 'with_debt'])['data'])->pluck('name');

    expect($names)->toContain('Con Deuda')
        ->and($names)->not->toContain('Sin Deuda');
});

it('ordena por deuda descendente', function () {
    makeClientWithInvoices('Deuda Baja', 'active', [
        ['status' => Invoice::STATUS_PENDING, 'total' => 10.00, 'due' => now()->addDay()->toDateString()],
    ]);
    makeClientWithInvoices('Deuda Alta', 'active', [
        ['status' => Invoice::STATUS_PENDING, 'total' => 500.00, 'due' => now()->addDay()->toDateString()],
    ]);

    $data = summaryFor(['per_page' => 50, 'sort' => 'debt', 'dir' => 'desc'])['data'];

    expect($data[0]['name'])->toBe('Deuda Alta');
});

it('publica los contadores de cartera en stats', function () {
    makeClientWithInvoices('Moroso', 'active', [
        ['status' => Invoice::STATUS_PENDING, 'total' => 75.00, 'due' => now()->subDay()->toDateString()],
    ]);
    makeClientWithInvoices('Puntual', 'active');

    $stats = summaryFor(['per_page' => 50])['stats'];

    expect($stats['total'])->toBe(2)
        ->and($stats['active'])->toBe(2)
        ->and($stats['with_debt'])->toBe(1)
        ->and((float) $stats['debt_amount'])->toBe(75.0);
});

it('encuentra clientes buscando por documento e IP', function () {
    $client = makeClientWithInvoices('Buscable Por IP', 'active');
    $client->update(['ip' => '10.20.30.40', 'document_id' => '0102030405']);

    expect(collect(summaryFor(['search' => '10.20.30.40'])['data'])->pluck('name'))
        ->toContain('Buscable Por IP');

    expect(collect(summaryFor(['search' => '0102030405'])['data'])->pluck('name'))
        ->toContain('Buscable Por IP');
});
