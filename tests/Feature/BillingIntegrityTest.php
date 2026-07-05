<?php

use App\Jobs\ReconcileBillingIntegrity;
use App\Models\AutomationSetting;
use App\Models\Audit;
use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\ClientServiceInterruption;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\BillingIntegrityService;
use App\Services\ClientSuspensionService;
use App\Services\MikroTikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function makeIntegrityClient(string $serviceStatus = 'ACTIVE', string $planStatus = 'active', ?string $ip = null): Client
{
    $suffix = uniqid();

    $client = Client::factory()->create(array_filter([
        'service_status' => $serviceStatus,
        'contract_date'  => now()->subMonths(3)->toDateString(),
        'ip'             => $ip,
    ], fn ($v) => $v !== null));

    $plan = Plan::factory()->create([
        'name' => "Plan Integridad {$suffix}",
        'slug' => "plan-integridad-{$suffix}",
    ]);

    ClientPlan::create([
        'client_id'         => $client->id,
        'plan_id'           => $plan->id,
        'start_date'        => $client->contract_date->toDateString(),
        'billing_cycle'     => 'monthly',
        'status'            => $planStatus,
        'next_billing_date' => now()->addMonth()->toDateString(),
        'current_price'     => 30.00,
    ]);

    return $client;
}

function makeIntegrityInvoice(Client $client, string $issueDate, string $status = Invoice::STATUS_PENDING): Invoice
{
    return Invoice::create([
        'client_id'      => $client->id,
        'client_plan_id' => $client->clientPlans()->first()->id,
        'invoice_number' => 'INV-INT-' . uniqid(),
        'issue_date'     => $issueDate,
        'due_date'       => Carbon::parse($issueDate)->addDays(15)->toDateString(),
        'amount'         => 30.00,
        'tax_amount'     => 0,
        'total_amount'   => 30.00,
        'status'         => $status,
    ]);
}

/**
 * Cambia el estado esquivando los eventos de Eloquent (mass-update), que es
 * exactamente la vía "fuera de flujo" que la conciliación debe detectar.
 */
function forceStatusBypassingObserver(Client $client, string $status): void
{
    Client::whereKey($client->id)->update(['service_status' => $status]);
}

beforeEach(function () {
    $this->mock(MikroTikService::class, function (MockInterface $m) {
        $m->shouldReceive('addIpToAddressList')->andReturn(['success' => true]);
        $m->shouldReceive('removeIpFromAddressList')->andReturn(['success' => true]);
    });
});

describe('BillingIntegrityService — invariantes', function () {

    it('reporta saludable cuando estados, ventanas y facturas son consistentes', function () {
        // Activo sin cortes + suspendido por el flujo correcto (ventana abierta,
        // planes suspendidos): ninguna inconsistencia.
        makeIntegrityClient('ACTIVE');
        $suspended = makeIntegrityClient('ACTIVE');
        app(ClientSuspensionService::class)->suspendClient($suspended, 'Impago en prueba');

        $report = app(BillingIntegrityService::class)->reconcile(checkMikrotik: false);

        expect($report['healthy'])->toBeTrue();
        expect($report['total_findings'])->toBe(0);
        expect(Audit::where('operation', 'BILLING_INTEGRITY_OP')->count())->toBe(0);
    });

    it('detecta un cliente con estado facturable pero ventana de corte abierta', function () {
        $client = makeIntegrityClient('ACTIVE');
        ClientServiceInterruption::create([
            'client_id'         => $client->id,
            'type'              => ClientServiceInterruption::TYPE_SUSPENSION,
            'suspended_at'      => now()->subDays(5),
            'suspension_reason' => 'corte sin reflejar en el estado',
            'source'            => 'manual',
        ]);

        $report = app(BillingIntegrityService::class)->reconcile(checkMikrotik: false);

        expect($report['healthy'])->toBeFalse();
        expect($report['findings']['active_with_open_cut'])->toHaveCount(1);
        expect($report['findings']['active_with_open_cut'][0]['client_id'])->toBe($client->id);
    });

    it('detecta un cliente cortado sin ventana abierta (cambio que esquivó el observer)', function () {
        $client = makeIntegrityClient('ACTIVE', 'suspended');
        forceStatusBypassingObserver($client, 'SUSPENDED');

        $report = app(BillingIntegrityService::class)->reconcile(checkMikrotik: false);

        expect($report['healthy'])->toBeFalse();
        expect($report['findings']['cut_without_open_window'])->toHaveCount(1);
        expect($report['findings']['cut_without_open_window'][0]['client_id'])->toBe($client->id);
    });

    it('detecta facturas emitidas dentro de una ventana de corte y respeta la regla de día', function () {
        $client = makeIntegrityClient('ACTIVE');
        ClientServiceInterruption::create([
            'client_id'      => $client->id,
            'type'           => ClientServiceInterruption::TYPE_SUSPENSION,
            'suspended_at'   => '2026-05-01 10:00:00',
            'reactivated_at' => '2026-06-01 09:00:00',
            'source'         => 'auto',
        ]);

        $inside        = makeIntegrityInvoice($client, '2026-05-15');            // dentro → hallazgo
        makeIntegrityInvoice($client, '2026-06-01');                              // día de reactivación → facturable
        makeIntegrityInvoice($client, '2026-04-20');                              // antes del corte → ok
        makeIntegrityInvoice($client, '2026-05-20', Invoice::STATUS_CANCELLED);   // anulada → se ignora

        $report = app(BillingIntegrityService::class)->reconcile(checkMikrotik: false);

        expect($report['findings']['invoices_issued_during_cut'])->toHaveCount(1);
        expect($report['findings']['invoices_issued_during_cut'][0]['invoice_id'])->toBe($inside->id);

        // El hallazgo queda trazado en la auditoría.
        expect(Audit::where('operation', 'BILLING_INTEGRITY_OP')->count())->toBe(1);
    });

    it('detecta un cliente cortado que conserva planes activos', function () {
        $client = makeIntegrityClient('ACTIVE');
        forceStatusBypassingObserver($client, 'SUSPENDED');
        // El plan quedó 'active' porque el corte no pasó por el flujo correcto.

        $report = app(BillingIntegrityService::class)->reconcile(checkMikrotik: false);

        $found = collect($report['findings']['cut_clients_with_active_plans'])
            ->firstWhere('client_id', $client->id);
        expect($found)->not->toBeNull();
        expect($found['active_plans_count'])->toBe(1);
    });

    it('omite el chequeo MikroTik cuando está deshabilitado, sin marcar inconsistencia', function () {
        config(['mikrotik.enabled' => false]);
        makeIntegrityClient('ACTIVE');

        $report = app(BillingIntegrityService::class)->reconcile(checkMikrotik: true);

        expect($report['findings']['mikrotik']['skipped'])->not->toBeNull();
        expect($report['healthy'])->toBeTrue();
    });

    it('compara la lista morosos del router contra la BD en ambas direcciones', function () {
        config(['mikrotik.enabled' => true]);

        // Suspendido correctamente pero su IP NO está en morosos → sigue navegando.
        $suspendido = makeIntegrityClient('ACTIVE', 'active', '192.168.77.10');
        app(ClientSuspensionService::class)->suspendClient($suspendido, 'Impago en prueba');

        // Activo cuya IP SÍ está en morosos → paga sin servicio.
        $bloqueado = makeIntegrityClient('ACTIVE', 'active', '192.168.77.20');

        $this->mock(MikroTikService::class, function (MockInterface $m) {
            $m->shouldReceive('getAddressListEntries')->with('morosos')->andReturn([
                'success' => true,
                'entries' => [['address' => '192.168.77.20', 'comment' => 'residuo']],
            ]);
        });

        $report = app(BillingIntegrityService::class)->reconcile(checkMikrotik: true);

        $mk = $report['findings']['mikrotik'];
        expect(collect($mk['suspended_not_in_morosos'])->pluck('client_id'))->toContain($suspendido->id);
        expect(collect($mk['in_morosos_not_suspended'])->pluck('client_id'))->toContain($bloqueado->id);
        expect($report['healthy'])->toBeFalse();
    });
});

describe('ReconcileBillingIntegrity — worker', function () {

    it('aborta sin fallar cuando no existe el registro de automatización', function () {
        AutomationSetting::where('key', 'billing_integrity')->delete();
        AutomationSetting::flushCache();

        app(ReconcileBillingIntegrity::class)->handle(app(BillingIntegrityService::class));

        expect(true)->toBeTrue(); // sin excepción = comportamiento esperado
    });

    it('no ejecuta la conciliación cuando la automatización está desactivada', function () {
        AutomationSetting::updateOrCreate(
            ['key' => 'billing_integrity'],
            ['enabled' => false, 'job_class' => ReconcileBillingIntegrity::class]
        );
        AutomationSetting::flushCache();

        $before = AutomationSetting::where('key', 'billing_integrity')->value('last_run_at');
        app(ReconcileBillingIntegrity::class)->handle(app(BillingIntegrityService::class));

        expect(AutomationSetting::where('key', 'billing_integrity')->value('last_run_at'))
            ->toBe($before);
    });

    it('ejecuta la conciliación y registra last_run_at cuando está habilitada', function () {
        // El seed de la migración deja 'billing_integrity' habilitada; se fuerza
        // check_mikrotik=false para aislar el test del router.
        AutomationSetting::updateOrCreate(
            ['key' => 'billing_integrity'],
            [
                'enabled'   => true,
                'job_class' => ReconcileBillingIntegrity::class,
                'params'    => ['check_mikrotik' => false],
            ]
        );
        AutomationSetting::flushCache();

        app(ReconcileBillingIntegrity::class)->handle(app(BillingIntegrityService::class));

        expect(AutomationSetting::where('key', 'billing_integrity')->value('last_run_at'))
            ->not->toBeNull();
    });
});
