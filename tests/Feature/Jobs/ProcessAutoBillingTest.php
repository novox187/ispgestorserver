<?php

use App\Jobs\ProcessAutoBilling;
use App\Models\AutomationSetting;
use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Wallet;
use App\Services\AutoBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function makeAutoBillingAutomation(bool $enabled = true, array $paramsOverride = []): AutomationSetting
{
    $params = array_merge([
        'days_before_due'   => 5,
        'retry_window_days' => 7,
        'max_retries'       => 3,
        'min_amount'        => 0.50,
        'max_amount'        => 10000.00,
        'notify_on_success' => false,
        'notify_on_failure' => true,
        'notify_on_retry'   => false,
    ], $paramsOverride);

    return AutomationSetting::updateOrCreate(
        ['key' => 'auto_billing'],
        [
            'name'            => 'Cobros Automáticos',
            'description'     => 'Procesa cobros',
            'job_class'       => ProcessAutoBilling::class,
            'queue'           => 'default',
            'enabled'         => $enabled,
            'schedule_type'   => 'daily',
            'schedule_config' => ['time' => '02:00'],
            'params'          => $params,
            'params_schema'   => [
                'days_before_due'   => ['type' => 'integer', 'min' => 0, 'max' => 30, 'required' => true],
                'retry_window_days' => ['type' => 'integer', 'min' => 1, 'max' => 30, 'required' => true],
                'max_retries'       => ['type' => 'integer', 'min' => 1, 'max' => 10, 'required' => true],
                'min_amount'        => ['type' => 'decimal', 'min' => 0, 'max' => 100000, 'required' => true],
                'max_amount'        => ['type' => 'decimal', 'min' => 0, 'max' => 1000000, 'required' => true],
                'notify_on_success' => ['type' => 'boolean', 'required' => false],
                'notify_on_failure' => ['type' => 'boolean', 'required' => false],
                'notify_on_retry'   => ['type' => 'boolean', 'required' => false],
            ],
        ]
    );
}

function makePendingInvoiceForActiveClient(float $total, float $walletBalance = 0): Invoice
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
        'current_price'     => $total,
    ]);

    Wallet::create([
        'client_id' => $client->id,
        'balance'   => $walletBalance,
    ]);

    return Invoice::create([
        'client_id'      => $client->id,
        'client_plan_id' => $clientPlan->id,
        'invoice_number' => 'INV-AB-' . uniqid(),
        'issue_date'     => now()->subDays(2)->toDateString(),
        'due_date'       => now()->addDays(2)->toDateString(),
        'amount'         => $total,
        'tax_amount'     => 0,
        'total_amount'   => $total,
        'status'         => Invoice::STATUS_PENDING,
    ]);
}

describe('ProcessAutoBilling::handle()', function () {

    it('NO ejecuta cobros cuando la automatización está DESACTIVADA', function () {
        makeAutoBillingAutomation(enabled: false);

        // El servicio NUNCA debe ser invocado cuando enabled=false
        $this->mock(AutoBillingService::class, function (MockInterface $m) {
            $m->shouldNotReceive('processAutoPayments');
        });

        app(ProcessAutoBilling::class)->handle(app(AutoBillingService::class));
    });

    it('aborta de forma segura cuando el AutomationSetting no existe', function () {
        AutomationSetting::where('key', 'auto_billing')->delete();

        $this->mock(AutoBillingService::class, function (MockInterface $m) {
            $m->shouldNotReceive('processAutoPayments');
        });

        app(ProcessAutoBilling::class)->handle(app(AutoBillingService::class));
    });

    it('procesa cobros cuando la automatización está ACTIVADA pasando los params como config', function () {
        $expectedConfig = [
            'days_before_due'   => 10,
            'retry_window_days' => 14,
            'max_retries'       => 5,
            'min_amount'        => 1.00,
            'max_amount'        => 500.00,
            'notify_on_success' => true,
            'notify_on_failure' => true,
            'notify_on_retry'   => true,
        ];

        makeAutoBillingAutomation(enabled: true, paramsOverride: $expectedConfig);

        $this->mock(AutoBillingService::class, function (MockInterface $m) use ($expectedConfig) {
            $m->shouldReceive('processAutoPayments')
              ->once()
              ->with($expectedConfig)
              ->andReturn(['processed' => [], 'retried_successfully' => 0]);
        });

        app(ProcessAutoBilling::class)->handle(app(AutoBillingService::class));
    });

    it('registra el estado de configuración (enabled, params) en cada ejecución', function () {
        makeAutoBillingAutomation(enabled: false, paramsOverride: ['max_retries' => 7]);

        Log::spy();

        app(ProcessAutoBilling::class)->handle(app(AutoBillingService::class));

        Log::shouldHaveReceived('info')
            ->withArgs(function ($message, $context = []) {
                return str_contains($message, 'estado de configuración')
                    && ($context['enabled'] ?? null) === false
                    && ($context['params']['max_retries'] ?? null) === 7;
            })
            ->once();

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message) => str_contains($message, 'DESACTIVADOS'))
            ->once();
    });

    it('actualiza last_run_at en la ejecución habilitada', function () {
        $auto = makeAutoBillingAutomation(enabled: true);
        expect($auto->last_run_at)->toBeNull();

        $this->mock(AutoBillingService::class, function (MockInterface $m) {
            $m->shouldReceive('processAutoPayments')->once()->andReturn(['processed' => []]);
        });

        app(ProcessAutoBilling::class)->handle(app(AutoBillingService::class));

        expect($auto->fresh()->last_run_at)->not->toBeNull();
    });
});

describe('AutoBillingService::processAutoPayments() — filtros de configuración', function () {

    it('omite facturas con monto inferior a min_amount', function () {
        $invoice = makePendingInvoiceForActiveClient(total: 0.10, walletBalance: 100);

        $result = app(AutoBillingService::class)->processAutoPayments([
            'min_amount'        => 1.00,
            'max_amount'        => 10000.00,
            'days_before_due'   => 5,
            'retry_window_days' => 7,
            'max_retries'       => 3,
        ]);

        expect($result['skipped_amount'])->toBe(1);

        // Factura NO fue pagada
        expect($invoice->fresh()->status)->toBe(Invoice::STATUS_PENDING);
    });

    it('omite facturas con monto superior a max_amount', function () {
        $invoice = makePendingInvoiceForActiveClient(total: 50000, walletBalance: 100000);

        $result = app(AutoBillingService::class)->processAutoPayments([
            'min_amount'        => 0.50,
            'max_amount'        => 1000.00,
            'days_before_due'   => 5,
            'retry_window_days' => 7,
            'max_retries'       => 3,
        ]);

        expect($result['skipped_amount'])->toBe(1);
        expect($invoice->fresh()->status)->toBe(Invoice::STATUS_PENDING);
    });

    it('omite facturas FAILED que ya superaron max_retries', function () {
        $invoice = makePendingInvoiceForActiveClient(total: 25.00, walletBalance: 100);
        $invoice->update([
            'status'   => Invoice::STATUS_FAILED,
            'metadata' => ['retry_count' => 5],
        ]);

        $result = app(AutoBillingService::class)->processAutoPayments([
            'min_amount'        => 0.50,
            'max_amount'        => 10000.00,
            'days_before_due'   => 5,
            'retry_window_days' => 7,
            'max_retries'       => 3,
        ]);

        expect($result['skipped_max_retries'])->toBe(1);
        // Sigue en FAILED, no se intentó cobrar
        expect($invoice->fresh()->status)->toBe(Invoice::STATUS_FAILED);
    });

    it('cobra exitosamente una factura dentro de los rangos cuando hay saldo', function () {
        $invoice = makePendingInvoiceForActiveClient(total: 25.00, walletBalance: 100);

        $result = app(AutoBillingService::class)->processAutoPayments([
            'min_amount'        => 0.50,
            'max_amount'        => 10000.00,
            'days_before_due'   => 5,
            'retry_window_days' => 7,
            'max_retries'       => 3,
        ]);

        expect($invoice->fresh()->status)->toBe(Invoice::STATUS_PAID);
        expect($result['skipped_amount'])->toBe(0);
        expect($result['skipped_max_retries'])->toBe(0);
    });
});
