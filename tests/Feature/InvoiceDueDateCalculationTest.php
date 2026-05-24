<?php

use App\Models\Audit;
use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Setting;
use App\Services\AutoBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Sembrar la configuración mínima válida para que buildInvoiceSnapshot()
 * pase la validación de InvoiceConfigValidator. Solo `invoice_due_days`
 * es variable; el resto se fija a valores válidos.
 */
function seedInvoiceConfig(int $invoiceDueDays = 15): void
{
    $base = [
        // issuer
        ['key' => 'issuer_name',    'value' => 'Iron Link S.A.',           'data_type' => 'string'],
        ['key' => 'issuer_ruc',     'value' => '1791234567001',            'data_type' => 'string'],
        ['key' => 'issuer_address', 'value' => 'Av. Amazonas 123',         'data_type' => 'string'],
        ['key' => 'issuer_city',    'value' => 'Quito',                    'data_type' => 'string'],
        ['key' => 'issuer_country', 'value' => 'Ecuador',                  'data_type' => 'string'],
        ['key' => 'issuer_email',   'value' => 'facturacion@ironlink.com', 'data_type' => 'string'],
        // tax
        ['key' => 'tax_rate', 'value' => '0.15', 'data_type' => 'float'],
        ['key' => 'tax_name', 'value' => 'IVA',  'data_type' => 'string'],
        // currency
        ['key' => 'currency_code',   'value' => 'USD', 'data_type' => 'string'],
        ['key' => 'currency_symbol', 'value' => '$',   'data_type' => 'string'],
        // legal
        ['key' => 'sri_establishment_code', 'value' => '001', 'data_type' => 'string'],
        ['key' => 'sri_emission_point',     'value' => '001', 'data_type' => 'string'],
        // billing internals
        ['key' => 'invoice_due_days', 'value' => (string) $invoiceDueDays, 'data_type' => 'integer'],
    ];

    foreach ($base as $row) {
        Setting::updateOrCreate(
            ['key' => $row['key']],
            array_merge($row, [
                'module'    => 'facturacion',
                'group'     => 'billing',
                'is_public' => false,
            ])
        );
    }

    Setting::flushCache();
}

function makeActiveClientWithPlan(): ClientPlan
{
    $client = Client::factory()->active()->create(['contract_date' => null]);
    $plan   = Plan::factory()->create(['monthly_price' => 50]);

    return ClientPlan::create([
        'client_id'         => $client->id,
        'plan_id'           => $plan->id,
        'start_date'        => now()->subMonths(2)->toDateString(),
        'billing_cycle'     => 'monthly',
        'status'            => 'active',
        'next_billing_date' => now()->addMonth()->toDateString(),
        'current_price'     => 50,
    ]);
}

describe('Cálculo de due_date con invoice_due_days configurable', function () {

    it('aplica el plazo configurado: 1 jun + 5 días = 6 jun', function () {
        Carbon::setTestNow(Carbon::parse('2026-06-01 10:00:00'));
        seedInvoiceConfig(invoiceDueDays: 5);
        makeActiveClientWithPlan();

        $generated = app(AutoBillingService::class)->generateMonthlyInvoices();

        expect($generated)->toHaveCount(1);
        $invoice = $generated[0];
        expect($invoice->issue_date->toDateString())->toBe('2026-06-01');
        expect($invoice->due_date->toDateString())->toBe('2026-06-06');

        Carbon::setTestNow();
    });

    it('cruza el cambio de mes correctamente: 28 feb + 5 días = 5 mar', function () {
        Carbon::setTestNow(Carbon::parse('2026-02-28 10:00:00'));
        seedInvoiceConfig(invoiceDueDays: 5);
        makeActiveClientWithPlan();

        $generated = app(AutoBillingService::class)->generateMonthlyInvoices();

        expect($generated)->toHaveCount(1);
        expect($generated[0]->due_date->toDateString())->toBe('2026-03-05');

        Carbon::setTestNow();
    });

    it('cruza el cambio de año correctamente: 30 dic + 5 días = 4 ene del año siguiente', function () {
        Carbon::setTestNow(Carbon::parse('2026-12-30 10:00:00'));
        seedInvoiceConfig(invoiceDueDays: 5);
        makeActiveClientWithPlan();

        $generated = app(AutoBillingService::class)->generateMonthlyInvoices();

        expect($generated)->toHaveCount(1);
        expect($generated[0]->due_date->toDateString())->toBe('2027-01-04');

        Carbon::setTestNow();
    });

    it('respeta plazos largos: 15 ene + 30 días = 14 feb', function () {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));
        seedInvoiceConfig(invoiceDueDays: 30);
        makeActiveClientWithPlan();

        $generated = app(AutoBillingService::class)->generateMonthlyInvoices();

        expect($generated[0]->due_date->toDateString())->toBe('2026-02-14');

        Carbon::setTestNow();
    });

    it('permite plazo de 0 días: emisión y vencimiento mismo día', function () {
        Carbon::setTestNow(Carbon::parse('2026-06-01 10:00:00'));
        seedInvoiceConfig(invoiceDueDays: 0);
        makeActiveClientWithPlan();

        $generated = app(AutoBillingService::class)->generateMonthlyInvoices();

        expect($generated[0]->due_date->toDateString())->toBe('2026-06-01');

        Carbon::setTestNow();
    });

    it('usa fallback de 15 días cuando invoice_due_days no existe en system_settings', function () {
        Carbon::setTestNow(Carbon::parse('2026-06-01 10:00:00'));

        // Sembrar todo MENOS invoice_due_days
        seedInvoiceConfig(invoiceDueDays: 15);
        Setting::where('key', 'invoice_due_days')->delete();
        Setting::flushCache();

        makeActiveClientWithPlan();

        $generated = app(AutoBillingService::class)->generateMonthlyInvoices();

        expect($generated[0]->due_date->toDateString())->toBe('2026-06-16');

        Carbon::setTestNow();
    });
});

describe('Auditoría INVOICE_DUE_CALC_OP', function () {

    it('emite un registro de auditoría con el desglose completo al crear factura', function () {
        Carbon::setTestNow(Carbon::parse('2026-06-01 10:00:00'));
        seedInvoiceConfig(invoiceDueDays: 7);
        makeActiveClientWithPlan();

        $generated = app(AutoBillingService::class)->generateMonthlyInvoices();
        $invoice   = $generated[0];

        $audit = Audit::where('table_name', 'invoices')
            ->where('operation', 'INVOICE_DUE_CALC_OP')
            ->where('record_id', (string) $invoice->id)
            ->first();

        expect($audit)->not->toBeNull();

        $new = $audit->new_values;
        expect($new['invoice_number'])->toBe($invoice->invoice_number);
        expect($new['issue_date'])->toBe('2026-06-01');
        expect($new['invoice_due_days'])->toBe(7);
        expect($new['invoice_due_days_source'])->toBe('system_settings');
        expect($new['due_date'])->toBe('2026-06-08');
        expect($new['billing_cycle'])->toBe('monthly');
        expect($new['computed_at'])->not->toBeNull();

        Carbon::setTestNow();
    });

    it('marca el origen como fallback cuando invoice_due_days no está en el snapshot', function () {
        Carbon::setTestNow(Carbon::parse('2026-06-01 10:00:00'));
        seedInvoiceConfig(invoiceDueDays: 15);
        Setting::where('key', 'invoice_due_days')->delete();
        Setting::flushCache();
        makeActiveClientWithPlan();

        $generated = app(AutoBillingService::class)->generateMonthlyInvoices();
        $invoice   = $generated[0];

        $audit = Audit::where('table_name', 'invoices')
            ->where('operation', 'INVOICE_DUE_CALC_OP')
            ->where('record_id', (string) $invoice->id)
            ->first();

        expect($audit->new_values['invoice_due_days_source'])->toBe('fallback_15');
        expect($audit->new_values['invoice_due_days'])->toBe(15);

        Carbon::setTestNow();
    });
});
