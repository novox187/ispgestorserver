<?php

namespace App\Jobs;

use App\Services\AutoBillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateMonthlyInvoices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 300;

    public function handle(AutoBillingService $billing): void
    {
        Log::info('GenerateMonthlyInvoices: Iniciando generación de facturas mensuales.');

        $invoices = $billing->generateMonthlyInvoices();
        $count    = count($invoices);

        Log::info("GenerateMonthlyInvoices: {$count} factura(s) generada(s) para " . now()->format('Y-m') . '.');
    }
}
