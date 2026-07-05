<?php

use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\ClientServiceInterruption;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\AutoBillingService;
use App\Services\ClientSuspensionService;
use App\Services\MikroTikService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/**
 * Snapshot de facturación válido y sin impuestos para aislar estas pruebas de
 * la configuración real del módulo (mismo patrón que WhitelistBillingTest).
 */
function fakeInvoiceSettingsCutTest(): void
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

function makeClientWithActivePlanCutTest(
    string $serviceStatus = 'ACTIVE',
    ?string $contractDate = null,
    float $price = 30.00
): Client {
    $suffix = uniqid();

    $client = Client::factory()->create([
        'service_status' => $serviceStatus,
        'contract_date'  => $contractDate ?? now()->subMonths(2)->toDateString(),
    ]);

    $plan = Plan::factory()->create([
        'name' => "Plan Corte {$suffix}",
        'slug' => "plan-corte-{$suffix}",
    ]);

    ClientPlan::create([
        'client_id'         => $client->id,
        'plan_id'           => $plan->id,
        'start_date'        => $client->contract_date->toDateString(),
        'billing_cycle'     => 'monthly',
        'status'            => 'active',
        'next_billing_date' => now()->addMonth()->toDateString(),
        'current_price'     => $price,
    ]);

    return $client;
}

function makePendingInvoiceCutTest(Client $client, float $total = 30.00): Invoice
{
    return Invoice::create([
        'client_id'      => $client->id,
        'client_plan_id' => $client->clientPlans()->first()->id,
        'invoice_number' => 'INV-CUT-' . uniqid(),
        'issue_date'     => now()->subDays(20)->toDateString(),
        'due_date'       => now()->subDays(5)->toDateString(),
        'amount'         => $total,
        'tax_amount'     => 0,
        'total_amount'   => $total,
        'status'         => Invoice::STATUS_PENDING,
    ]);
}

beforeEach(function () {
    // Aislar MikroTik: las suspensiones/reactivaciones no deben tocar el router.
    $this->mock(MikroTikService::class, function (MockInterface $m) {
        $m->shouldReceive('addIpToAddressList')->andReturn(['success' => true]);
        $m->shouldReceive('removeIpFromAddressList')->andReturn(['success' => true]);
    });
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('Generación mensual — validación por fecha de corte', function () {

    it('factura a un cliente ACTIVO y NO duplica en una segunda corrida', function () {
        fakeInvoiceSettingsCutTest();
        $client = makeClientWithActivePlanCutTest();

        $first  = app(AutoBillingService::class)->generateMonthlyInvoices();
        $second = app(AutoBillingService::class)->generateMonthlyInvoices();

        expect($first)->toHaveCount(1);
        expect($second)->toHaveCount(0);
        expect(Invoice::where('client_id', $client->id)->count())->toBe(1);
    });

    it('NO factura a un cliente suspendido por el flujo de corte', function () {
        fakeInvoiceSettingsCutTest();
        $client = makeClientWithActivePlanCutTest();

        app(ClientSuspensionService::class)->suspendClient($client, 'Impago en prueba');

        $invoices = app(AutoBillingService::class)->generateMonthlyInvoices();

        expect($invoices)->toHaveCount(0);
        expect(Invoice::where('client_id', $client->id)->count())->toBe(0);
    });

    it('NO factura si el estado quedó ACTIVO pero existe una ventana de corte vigente (dato inconsistente)', function () {
        fakeInvoiceSettingsCutTest();

        // El estado dice ACTIVE y el plan sigue active: el filtro por estado NO
        // lo excluye. La validación por fecha de corte debe detener la emisión.
        $client = makeClientWithActivePlanCutTest();
        ClientServiceInterruption::create([
            'client_id'         => $client->id,
            'type'              => ClientServiceInterruption::TYPE_SUSPENSION,
            'suspended_at'      => now()->subDays(10),
            'suspension_reason' => 'corte registrado sin actualizar el estado',
            'source'            => 'manual',
        ]);

        $invoices = app(AutoBillingService::class)->generateMonthlyInvoices();

        expect($invoices)->toHaveCount(0);
        expect(Invoice::where('client_id', $client->id)->count())->toBe(0);
    });

    it('vuelve a facturar el ciclo vigente tras la reactivación', function () {
        fakeInvoiceSettingsCutTest();
        $client = makeClientWithActivePlanCutTest();

        $suspension = app(ClientSuspensionService::class);
        $suspension->suspendClient($client, 'Impago en prueba');
        $suspension->reactivateClient($client, 'Pago recibido en prueba');

        $invoices = app(AutoBillingService::class)->generateMonthlyInvoices();

        expect($invoices)->toHaveCount(1);
        expect(Invoice::where('client_id', $client->id)->count())->toBe(1);
    });
});

describe('Generación por fecha de contrato — fecha límite de corte', function () {

    it('omite los ciclos dentro de la ventana de suspensión y NO duplica al reejecutar (reactivación posterior)', function () {
        Carbon::setTestNow(Carbon::parse('2026-07-04 12:00:00'));
        fakeInvoiceSettingsCutTest();

        // Contrato el 4 de marzo → ciclos: 03-04, 04-04, 05-04, 06-04, 07-04.
        $client = makeClientWithActivePlanCutTest('ACTIVE', '2026-03-04');

        // Suspendido el 20 de abril y reactivado el 10 de junio: los ciclos que
        // inician el 05-04 y el 06-04 caen dentro del corte y no se facturan.
        ClientServiceInterruption::create([
            'client_id'           => $client->id,
            'type'                => ClientServiceInterruption::TYPE_SUSPENSION,
            'suspended_at'        => Carbon::parse('2026-04-20 08:00:00'),
            'reactivated_at'      => Carbon::parse('2026-06-10 09:30:00'),
            'suspension_reason'   => 'ventana histórica de impago',
            'reactivation_reason' => 'pago recibido',
            'source'              => 'auto',
        ]);

        $report = app(AutoBillingService::class)->generateInvoicesByContractDate();

        expect($report['generated_count'])->toBe(3);

        $cutSkipped = collect($report['skipped'])
            ->where('reason', 'servicio suspendido/cortado en la fecha de inicio del ciclo');
        expect($cutSkipped)->toHaveCount(2);
        expect($cutSkipped->pluck('cycle_start')->sort()->values()->all())
            ->toBe(['2026-05-04', '2026-06-04']);

        $issueDates = Invoice::where('client_id', $client->id)
            ->orderBy('issue_date')
            ->pluck('issue_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();
        expect($issueDates)->toBe(['2026-03-04', '2026-04-04', '2026-07-04']);

        // Reejecución: nada nuevo, los ciclos del corte siguen omitidos.
        $rerun = app(AutoBillingService::class)->generateInvoicesByContractDate();
        expect($rerun['generated_count'])->toBe(0);
        expect(Invoice::where('client_id', $client->id)->count())->toBe(3);
    });

    it('con un corte vigente (sin reactivar) no genera ciclos desde la fecha de corte', function () {
        Carbon::setTestNow(Carbon::parse('2026-07-04 12:00:00'));
        fakeInvoiceSettingsCutTest();

        // Estado inconsistente: sigue ACTIVE pero hay corte abierto desde el 1 de junio.
        $client = makeClientWithActivePlanCutTest('ACTIVE', '2026-04-04');
        ClientServiceInterruption::create([
            'client_id'         => $client->id,
            'type'              => ClientServiceInterruption::TYPE_SUSPENSION,
            'suspended_at'      => Carbon::parse('2026-06-01 10:00:00'),
            'suspension_reason' => 'corte vigente sin reflejar en el estado',
            'source'            => 'manual',
        ]);

        $report = app(AutoBillingService::class)->generateInvoicesByContractDate();

        // Ciclos 04-04 y 05-04 se facturan; 06-04 y 07-04 quedan tras el límite.
        expect($report['generated_count'])->toBe(2);

        $issueDates = Invoice::where('client_id', $client->id)
            ->orderBy('issue_date')
            ->pluck('issue_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();
        expect($issueDates)->toBe(['2026-04-04', '2026-05-04']);
    });
});

describe('Registro de ventanas de corte (observer + servicios)', function () {

    it('suspendClient abre la ventana con fecha, razón y factura origen; reactivateClient la cierra', function () {
        $client  = makeClientWithActivePlanCutTest();
        $invoice = makePendingInvoiceCutTest($client);

        app(ClientSuspensionService::class)->suspendClient($client, 'Factura vencida', $invoice->id);

        $window = $client->fresh()->openServiceInterruption();
        expect($window)->not->toBeNull();
        expect($window->suspended_at)->not->toBeNull();
        expect($window->suspension_reason)->toBe('Factura vencida');
        expect($window->suspended_by)->toBe('system_auto');
        expect($window->invoice_id)->toBe($invoice->id);
        expect($window->source)->toBe('auto');
        expect($window->type)->toBe(ClientServiceInterruption::TYPE_SUSPENSION);

        app(ClientSuspensionService::class)->reactivateClient($client, 'Pago recibido');

        expect($client->fresh()->openServiceInterruption())->toBeNull();

        $closed = ClientServiceInterruption::where('client_id', $client->id)->sole();
        expect($closed->reactivated_at)->not->toBeNull();
        expect($closed->reactivation_reason)->toBe('Pago recibido');
        expect($closed->reactivated_by)->toBe('system_auto');
    });

    it('un cambio manual de estado (update directo) también abre y cierra la ventana', function () {
        $client = makeClientWithActivePlanCutTest();

        $client->update(['service_status' => 'SUSPENDED']);

        $window = $client->openServiceInterruption();
        expect($window)->not->toBeNull();
        expect($window->source)->toBe('status_change');

        $client->update(['service_status' => 'ACTIVE']);

        expect($client->fresh()->openServiceInterruption())->toBeNull();
        expect(ClientServiceInterruption::where('client_id', $client->id)->whereNotNull('reactivated_at')->count())->toBe(1);
    });

    it('no duplica la ventana al pasar de suspendido a cancelado: el corte continúa como baja', function () {
        $client = makeClientWithActivePlanCutTest();

        $client->update(['service_status' => 'SUSPENDED']);
        $client->update(['service_status' => 'CANCELLED']);

        expect(ClientServiceInterruption::where('client_id', $client->id)->count())->toBe(1);
        expect($client->openServiceInterruption()->type)->toBe(ClientServiceInterruption::TYPE_CANCELLATION);
    });

    it('un cliente creado directamente como suspendido queda con ventana abierta', function () {
        $client = Client::factory()->create(['service_status' => 'SUSPENDED']);

        expect($client->openServiceInterruption())->not->toBeNull();
    });
});
