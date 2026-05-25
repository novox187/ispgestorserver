<?php

namespace App\Jobs;

use App\Jobs\Concerns\NotifiesWorkerSummary;
use App\Services\AutoBillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateMonthlyInvoices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, NotifiesWorkerSummary;

    public int $tries   = 2;
    public int $timeout = 300;

    public function handle(AutoBillingService $billing): void
    {
        Log::info('GenerateMonthlyInvoices: Iniciando generación de facturas mensuales.');

        $invoices = $billing->generateMonthlyInvoices();
        $count    = count($invoices);
        $period   = now()->format('Y-m');

        Log::info("GenerateMonthlyInvoices: {$count} factura(s) generada(s) para {$period}.");

        $this->notifyWorkerSummary(
            workerName: 'GenerateMonthlyInvoices',
            result:     [
                'generated_count' => $count,
                'period'          => $period,
            ],
            objective:  'Generar facturas del mes para clientes activos',
        );
    }

    public function failed(Throwable $exception): void
    {
        $this->notifyWorkerFailure(
            workerName: 'GenerateMonthlyInvoices',
            exception:  $exception,
            objective:  'Generar facturas del mes para clientes activos',
        );
    }
}
