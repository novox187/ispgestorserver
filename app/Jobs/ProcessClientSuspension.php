<?php

namespace App\Jobs;

use App\Models\AutomationSetting;
use App\Models\Invoice;
use App\Services\AutoBillingService;
use App\Services\ClientSuspensionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessClientSuspension implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Intentos antes de enviar a failed_jobs */
    public int $tries = 3;

    /** Segundos entre reintentos */
    public int $backoff = 300;

    /** Segundos máximos de ejecución */
    public int $timeout = 120;

    public function handle(ClientSuspensionService $suspension, AutoBillingService $billing): void
    {
        $graceDays = (int) AutomationSetting::getParam(
            'client_suspension',
            'grace_days',
            config('billing.suspension_grace_days', 3)
        );

        // updateQuietly evita generar entradas de auditoría por cada ejecución del job
        $automation = AutomationSetting::getCached('client_suspension');
        if ($automation) {
            $automation->updateQuietly(['last_run_at' => now()]);
        }

        $overdueInvoices = Invoice::with(['client.wallet'])
            ->where('status', Invoice::STATUS_FAILED)
            ->where('due_date', '<=', now()->subDays($graceDays)->toDateString())
            ->whereHas('client', function ($q) {
                $q->whereNotIn('service_status', [
                    'suspended', 'SUSPENDED', 'SUSPENDIDO',
                    'cancelled', 'CANCELLED',
                ]);
            })
            ->get();

        if ($overdueInvoices->isEmpty()) {
            Log::info('ProcessClientSuspension: Sin candidatos a suspender.');
            return;
        }

        Log::info("ProcessClientSuspension: {$overdueInvoices->count()} candidato(s) con {$graceDays} día(s) de gracia cumplidos.");

        $suspended = 0;
        $recovered = 0;
        $errors    = 0;

        foreach ($overdueInvoices as $invoice) {
            $client = $invoice->client;

            // Último intento de cobro antes de cortar el servicio
            try {
                $pay = $billing->processInvoicePayment($invoice);
                if ($pay['success']) {
                    Log::info("ProcessClientSuspension: Pago recuperado en último intento. Cliente {$client->id}, factura {$invoice->invoice_number}.");
                    $recovered++;
                    continue;
                }
            } catch (\Throwable $e) {
                Log::warning("ProcessClientSuspension: Error en último intento de cobro para cliente {$client->id}: " . $e->getMessage());
            }

            // Proceder con la suspensión
            try {
                $result = $suspension->suspendClient(
                    $client,
                    "Factura {$invoice->invoice_number} vencida con {$graceDays} día(s) de gracia",
                    $invoice->id
                );

                if ($result['already_suspended'] ?? false) {
                    Log::info("ProcessClientSuspension: Cliente {$client->id} ya estaba suspendido.");
                } else {
                    $suspended++;
                }
            } catch (\Throwable $e) {
                // Loggear pero NO relanzar: un error en un cliente no debe detener al resto
                Log::error("ProcessClientSuspension: Error suspendiendo cliente {$client->id}: " . $e->getMessage());
                $errors++;
            }
        }

        Log::info("ProcessClientSuspension finalizado. Suspendidos: {$suspended}, Recuperados: {$recovered}, Errores: {$errors}.");
    }
}
