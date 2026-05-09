<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Invoice;
use App\Services\AutoBillingService;
use App\Services\ClientSuspensionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAutoReactivation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60;
    public int $timeout = 60;

    public function __construct(
        private readonly Client $client
    ) {}

    public function handle(ClientSuspensionService $suspension, AutoBillingService $billing): void
    {
        // Recargar estado más reciente desde la BD
        $this->client->refresh();

        if (!in_array(strtoupper($this->client->service_status), ['SUSPENDED', 'SUSPENDIDO'])) {
            return; // Ya está activo, nada que hacer
        }

        $pendingInvoices = Invoice::where('client_id', $this->client->id)
            ->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_FAILED])
            ->orderBy('due_date')
            ->get();

        if ($pendingInvoices->isEmpty()) {
            // Sin facturas pendientes: reactivar directamente
            $suspension->reactivateClient($this->client, 'Sin facturas pendientes tras recarga');
            return;
        }

        // Intentar pagar todas las facturas pendientes
        $allPaid = true;
        foreach ($pendingInvoices as $invoice) {
            try {
                $result = $billing->processInvoicePayment($invoice);
                if (!$result['success']) {
                    $allPaid = false;
                    Log::info("ProcessAutoReactivation: Saldo insuficiente para factura {$invoice->invoice_number}. Cliente {$this->client->id} no se reactiva aún.");
                    break;
                }
            } catch (\Throwable $e) {
                $allPaid = false;
                Log::error("ProcessAutoReactivation: Error procesando factura {$invoice->id}: " . $e->getMessage());
                break;
            }
        }

        if ($allPaid) {
            $suspension->reactivateClient($this->client, 'Pago completado tras recarga de wallet');
        }
    }
}
