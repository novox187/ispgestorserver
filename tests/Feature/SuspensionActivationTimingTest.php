<?php

use App\Jobs\ProcessClientSuspension;
use App\Models\Audit;
use App\Models\AutomationSetting;
use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\AutoBillingService;
use App\Services\ClientSuspensionService;
use App\Services\MikroTikService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function seedSuspensionFlow(int $graceDays = 3): void
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

function makeFailedInvoiceWithDueDate(string $dueDate): Invoice
{
    $client = Client::factory()->active()->create();
    $plan   = Plan::factory()->create();

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
        'invoice_number' => 'INV-S-' . uniqid(),
        'issue_date'     => Carbon::parse($dueDate)->subDays(5)->toDateString(),
        'due_date'       => $dueDate,
        'amount'         => 25.00,
        'tax_amount'     => 0,
        'total_amount'   => 25.00,
        'status'         => Invoice::STATUS_FAILED,
    ]);
}

beforeEach(function () {
    // Aislar MikroTik
    $this->mock(MikroTikService::class, function (MockInterface $m) {
        $m->shouldReceive('addIpToAddressList')->andReturn(['success' => true]);
        $m->shouldReceive('removeIpFromAddressList')->andReturn(['success' => true]);
    });

    // El último intento de cobro siempre falla — forzar suspensión
    $this->mock(AutoBillingService::class, function (MockInterface $m) {
        $m->shouldReceive('processInvoicePayment')->andReturn([
            'success' => false,
            'error'   => 'mocked',
        ]);
    });
});

describe('Activación puntual de la suspensión al cumplirse la fecha de vencimiento', function () {

    it('NO suspende cuando hoy < due_date + grace_days', function () {
        // Vencimiento el 10 de junio, gracia 3 días → suspender a partir del 13 de junio.
        // Hoy es 12 de junio → todavía dentro del periodo de gracia.
        Carbon::setTestNow(Carbon::parse('2026-06-12 02:00:00'));
        seedSuspensionFlow(graceDays: 3);
        $invoice = makeFailedInvoiceWithDueDate('2026-06-10');
        $client  = $invoice->client;

        app(ProcessClientSuspension::class)->handle(
            app(ClientSuspensionService::class),
            app(AutoBillingService::class)
        );

        expect(strtoupper($client->fresh()->service_status))->not->toBe('SUSPENDED');

        Carbon::setTestNow();
    });

    it('suspende cuando hoy = due_date + grace_days (umbral exacto)', function () {
        // Vencimiento el 10 de junio, gracia 3 días → primer día con corte: 13 de junio.
        Carbon::setTestNow(Carbon::parse('2026-06-13 02:00:00'));
        seedSuspensionFlow(graceDays: 3);
        $invoice = makeFailedInvoiceWithDueDate('2026-06-10');
        $client  = $invoice->client;

        app(ProcessClientSuspension::class)->handle(
            app(ClientSuspensionService::class),
            app(AutoBillingService::class)
        );

        expect(strtoupper($client->fresh()->service_status))->toBe('SUSPENDED');

        Carbon::setTestNow();
    });

    it('suspende cuando hoy > due_date + grace_days', function () {
        Carbon::setTestNow(Carbon::parse('2026-06-20 02:00:00'));
        seedSuspensionFlow(graceDays: 3);
        $invoice = makeFailedInvoiceWithDueDate('2026-06-10');
        $client  = $invoice->client;

        app(ProcessClientSuspension::class)->handle(
            app(ClientSuspensionService::class),
            app(AutoBillingService::class)
        );

        expect(strtoupper($client->fresh()->service_status))->toBe('SUSPENDED');

        Carbon::setTestNow();
    });

    it('respeta el cruce de mes: vencimiento 28 feb, gracia 3 → suspender desde 3 mar', function () {
        seedSuspensionFlow(graceDays: 3);
        $invoice = makeFailedInvoiceWithDueDate('2026-02-28');
        $client  = $invoice->client;

        // El 2 de marzo todavía no debe suspender.
        Carbon::setTestNow(Carbon::parse('2026-03-02 02:00:00'));
        app(ProcessClientSuspension::class)->handle(
            app(ClientSuspensionService::class),
            app(AutoBillingService::class)
        );
        expect(strtoupper($client->fresh()->service_status))->not->toBe('SUSPENDED');

        // El 3 de marzo sí.
        Carbon::setTestNow(Carbon::parse('2026-03-03 02:00:00'));
        app(ProcessClientSuspension::class)->handle(
            app(ClientSuspensionService::class),
            app(AutoBillingService::class)
        );
        expect(strtoupper($client->fresh()->service_status))->toBe('SUSPENDED');

        Carbon::setTestNow();
    });

    it('respeta el cruce de año: vencimiento 30 dic, gracia 5 → suspender desde 4 ene', function () {
        seedSuspensionFlow(graceDays: 5);
        $invoice = makeFailedInvoiceWithDueDate('2026-12-30');
        $client  = $invoice->client;

        // 3 de enero: aún en gracia.
        Carbon::setTestNow(Carbon::parse('2027-01-03 02:00:00'));
        app(ProcessClientSuspension::class)->handle(
            app(ClientSuspensionService::class),
            app(AutoBillingService::class)
        );
        expect(strtoupper($client->fresh()->service_status))->not->toBe('SUSPENDED');

        // 4 de enero: corte.
        Carbon::setTestNow(Carbon::parse('2027-01-04 02:00:00'));
        app(ProcessClientSuspension::class)->handle(
            app(ClientSuspensionService::class),
            app(AutoBillingService::class)
        );
        expect(strtoupper($client->fresh()->service_status))->toBe('SUSPENDED');

        Carbon::setTestNow();
    });
});

describe('Auditoría SUSPEND_AUTO_OP con trazabilidad de factura', function () {

    it('incluye invoice_trace con issue_date, due_date y configured_due_days al suspender', function () {
        Carbon::setTestNow(Carbon::parse('2026-06-20 02:00:00'));
        seedSuspensionFlow(graceDays: 3);
        // issue_date será 2026-06-05 (5 días antes del vencimiento)
        $invoice = makeFailedInvoiceWithDueDate('2026-06-10');
        $client  = $invoice->client;

        app(ProcessClientSuspension::class)->handle(
            app(ClientSuspensionService::class),
            app(AutoBillingService::class)
        );

        $audit = Audit::where('table_name', 'clients')
            ->where('operation', 'SUSPEND_AUTO_OP')
            ->where('record_id', (string) $client->id)
            ->latest()
            ->first();

        expect($audit)->not->toBeNull();

        $new = $audit->new_values;
        expect($new['service_status'])->toBe('suspended');
        expect($new['suspension_activated_at'])->not->toBeNull();
        expect($new['invoice_trace'])->not->toBeNull();
        expect($new['invoice_trace']['invoice_id'])->toBe($invoice->id);
        expect($new['invoice_trace']['issue_date'])->toBe('2026-06-05');
        expect($new['invoice_trace']['due_date'])->toBe('2026-06-10');
        expect($new['invoice_trace']['configured_due_days'])->toBe(5);

        Carbon::setTestNow();
    });
});
