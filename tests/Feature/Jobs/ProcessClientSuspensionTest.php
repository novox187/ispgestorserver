<?php

use App\Jobs\ProcessClientSuspension;
use App\Models\AutomationSetting;
use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\AutoBillingService;
use App\Services\ClientSuspensionService;
use App\Services\MikroTikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/**
 * Crea el AutomationSetting "client_suspension" base. Por defecto enabled=true.
 */
function makeSuspensionAutomation(bool $enabled = true, int $graceDays = 3): AutomationSetting
{
    return AutomationSetting::updateOrCreate(
        ['key' => 'client_suspension'],
        [
            'name'            => 'Suspensión Automática de Clientes',
            'description'     => 'Suspende clientes con facturas vencidas',
            'job_class'       => ProcessClientSuspension::class,
            'queue'           => 'suspensions',
            'enabled'         => $enabled,
            'schedule_type'   => 'daily',
            'schedule_config' => ['time' => '02:00'],
            'params'          => ['grace_days' => $graceDays],
            'params_schema'   => [
                'grace_days' => ['type' => 'integer', 'min' => 0, 'max' => 30, 'required' => true],
            ],
        ]
    );
}

/**
 * Crea un cliente activo con una factura vencida más allá del periodo de gracia.
 */
function makeOverdueClientWithFailedInvoice(int $graceDays = 3): Client
{
    $client = Client::factory()->active()->create();
    $plan   = Plan::factory()->create();

    $clientPlan = ClientPlan::create([
        'client_id'         => $client->id,
        'plan_id'           => $plan->id,
        'start_date'        => now()->subMonths(2)->toDateString(),
        'billing_cycle'     => 'monthly',
        'status'            => 'active',
        'next_billing_date' => now()->addMonth()->toDateString(),
        'current_price'     => 25.00,
    ]);

    Invoice::create([
        'client_id'       => $client->id,
        'client_plan_id'  => $clientPlan->id,
        'invoice_number'  => 'INV-TEST-' . uniqid(),
        'issue_date'      => now()->subDays($graceDays + 10)->toDateString(),
        'due_date'        => now()->subDays($graceDays + 5)->toDateString(),
        'amount'          => 25.00,
        'tax_amount'      => 0,
        'total_amount'    => 25.00,
        'status'          => Invoice::STATUS_FAILED,
    ]);

    return $client;
}

beforeEach(function () {
    // Aislar el job de la red real
    $this->mock(MikroTikService::class, function (MockInterface $m) {
        $m->shouldReceive('addIpToAddressList')->andReturn(['success' => true]);
        $m->shouldReceive('removeIpFromAddressList')->andReturn(['success' => true]);
    });

    // El último intento de cobro siempre falla (forzando el camino de suspensión)
    $this->mock(AutoBillingService::class, function (MockInterface $m) {
        $m->shouldReceive('processInvoicePayment')->andReturn([
            'success' => false,
            'error'   => 'mocked failure',
        ]);
    });
});

describe('ProcessClientSuspension::handle()', function () {

    it('suspende a clientes con deuda cuando la automatización está ACTIVADA', function () {
        makeSuspensionAutomation(enabled: true, graceDays: 3);
        $client = makeOverdueClientWithFailedInvoice(graceDays: 3);

        app(ProcessClientSuspension::class)->handle(
            app(ClientSuspensionService::class),
            app(AutoBillingService::class)
        );

        expect(strtoupper($client->fresh()->service_status))->toBe('SUSPENDED');
    });

    it('NO suspende a ningún cliente cuando la automatización está DESACTIVADA', function () {
        makeSuspensionAutomation(enabled: false, graceDays: 3);
        $client = makeOverdueClientWithFailedInvoice(graceDays: 3);

        $statusBefore = $client->service_status;

        app(ProcessClientSuspension::class)->handle(
            app(ClientSuspensionService::class),
            app(AutoBillingService::class)
        );

        // El estado del cliente NO debe haber cambiado
        expect($client->fresh()->service_status)->toBe($statusBefore);
    });

    it('aborta de forma segura cuando el AutomationSetting no existe', function () {
        // No crear el registro de automatización
        AutomationSetting::where('key', 'client_suspension')->delete();
        $client = makeOverdueClientWithFailedInvoice(graceDays: 3);

        $statusBefore = $client->service_status;

        app(ProcessClientSuspension::class)->handle(
            app(ClientSuspensionService::class),
            app(AutoBillingService::class)
        );

        expect($client->fresh()->service_status)->toBe($statusBefore);
    });

    it('registra el estado de configuración (enabled, params) en cada ejecución', function () {
        makeSuspensionAutomation(enabled: false, graceDays: 7);

        Log::spy();

        app(ProcessClientSuspension::class)->handle(
            app(ClientSuspensionService::class),
            app(AutoBillingService::class)
        );

        // El log de estado debe incluir enabled=false y los params
        Log::shouldHaveReceived('info')
            ->withArgs(function ($message, $context = []) {
                return str_contains($message, 'estado de configuración')
                    && ($context['enabled'] ?? null) === false
                    && ($context['params']['grace_days'] ?? null) === 7;
            })
            ->once();

        // El log de "desactivada" también debe haberse emitido
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message) => str_contains($message, 'DESACTIVADA'))
            ->once();
    });

    it('respeta un cambio reciente del flag enabled (no usa cache obsoleto)', function () {
        // Comienza activada
        $auto = makeSuspensionAutomation(enabled: true, graceDays: 3);

        // Calienta el cache
        AutomationSetting::getCached('client_suspension');

        // Desactiva en BD (la propia callback ::saved limpia el cache, pero
        // simulamos un escenario de cache obsoleto manualmente)
        $auto->update(['enabled' => false]);

        $client = makeOverdueClientWithFailedInvoice(graceDays: 3);
        $statusBefore = $client->service_status;

        app(ProcessClientSuspension::class)->handle(
            app(ClientSuspensionService::class),
            app(AutoBillingService::class)
        );

        expect($client->fresh()->service_status)->toBe($statusBefore);
    });
});
