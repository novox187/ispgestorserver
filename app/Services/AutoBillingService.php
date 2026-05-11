<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Wallet;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoBillingService
{
    /**
     * Generar facturas mensuales para todos los clientes activos
     */
    public function generateMonthlyInvoices()
    {
        $settingService = app(SettingService::class);

        // Build the snapshot once and reuse it for all invoices in this run.
        // If required settings are missing, abort the entire batch immediately.
        try {
            $snapshot = $settingService->buildInvoiceSnapshot();
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('billing')->error('Generación mensual abortada: faltan configuraciones', [
                'errors' => $e->errors(),
            ]);
            return [];
        }

        $taxRate = $settingService->taxRateFromSnapshot($snapshot);

        $clientsWithPlans = Client::whereHas('clientPlans', function ($query) {
            $query->where('status', 'active');
        })
            ->with(['clientPlans.plan'])
            ->get();

        $generatedInvoices = [];

        foreach ($clientsWithPlans as $client) {
            foreach ($client->clientPlans as $clientPlan) {
                $invoice = $this->createMonthlyInvoice($client, $clientPlan, $snapshot, $taxRate);
                if ($invoice) {
                    $generatedInvoices[] = $invoice;
                }
            }
        }

        return $generatedInvoices;
    }

    /**
     * Crear factura mensual para un cliente
     */
    private function createMonthlyInvoice(Client $client, $clientPlan, array $snapshot, float $taxRate): ?Invoice
    {
        // Verificar si ya existe una factura para este mes
        $existingInvoice = Invoice::where('client_id', $client->id)
            ->where('client_plan_id', $clientPlan->id)
            ->whereYear('issue_date', now()->year)
            ->whereMonth('issue_date', now()->month)
            ->first();

        if ($existingInvoice) {
            return null;
        }

        $amount      = (float) ($clientPlan->current_price ?? $clientPlan->plan->monthly_price);
        $taxAmount   = round($amount * $taxRate, 2);
        $totalAmount = round($amount + $taxAmount, 2);

        return Invoice::create([
            'client_id'              => $client->id,
            'client_plan_id'         => $clientPlan->id,
            'invoice_number'         => Invoice::generateInvoiceNumber(),
            'issue_date'             => now(),
            'due_date'               => now()->addDays(15),
            'amount'                 => $amount,
            'tax_amount'             => $taxAmount,
            'total_amount'           => $totalAmount,
            'status'                 => Invoice::STATUS_PENDING,
            'description'            => "Factura mensual - {$clientPlan->plan->name}",
            'configuration_snapshot' => $snapshot,
            'metadata'               => [
                'billing_cycle'   => 'monthly',
                'plan_name'       => $clientPlan->plan->name,
                'download_speed'  => $clientPlan->plan->download_speed,
                'upload_speed'    => $clientPlan->plan->upload_speed,
            ],
        ]);
    }

    /**
     * Procesar pagos automáticos
     */
    public function processAutoPayments()
    {
        // Buscar facturas pendientes O fallidas que estén en fecha de pago
        $invoices = Invoice::with(['client.wallet'])
            ->where(function ($query) {
                $query->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_FAILED])->where('due_date', '<=', now()->addDays(5));
            })
            ->orWhere(function ($query) {
                // También incluir facturas fallidas recientes (últimos 7 días) para reintento
                $query->where('status', Invoice::STATUS_FAILED)->where('created_at', '>=', now()->subDays(7));
            })
            ->get();

        // ELIMINADO: $this->info("🔍 Encontradas {$invoices->count()} facturas para procesar...");

        $results = [];
        $reintentosExitosos = 0;

        foreach ($invoices as $invoice) {
            $estadoAnterior = $invoice->status;
            $result = $this->processInvoicePayment($invoice);

            $results[] = [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'client_id' => $invoice->client_id,
                'previous_status' => $estadoAnterior,
                'result' => $result,
            ];

            // Contar reintentos exitosos
            if ($estadoAnterior === Invoice::STATUS_FAILED && $result['success']) {
                $reintentosExitosos++;
            }
        }

        // ELIMINADO: if ($reintentosExitosos > 0) { $this->info(...); }

        return $results;
    }

    /**
     * Procesar pago de una factura individual
     */
    public function processInvoicePayment(Invoice $invoice)
    {
        return DB::transaction(function () use ($invoice) {
            $client = $invoice->client;
            $estadoAnterior = $invoice->status;

            // Asegurar que el cliente tenga una wallet
            if (!$client->wallet) {
                $client->wallet()->create(['balance' => 0]);
                $client->load('wallet');
            }

            $wallet = $client->wallet;

            // ELIMINADO: $this->info("   💳 Procesando: {$invoice->invoice_number}...");

            // Verificar saldo suficiente
            if ($wallet->balance < $invoice->total_amount) {
                // ELIMINADO: $this->error("   ❌ Saldo insuficiente: {$wallet->balance} < {$invoice->total_amount}");

                // Solo actualizar a FAILED si no estaba ya en ese estado
                if ($invoice->status !== Invoice::STATUS_FAILED) {
                    $invoice->update(['status' => Invoice::STATUS_FAILED]);
                }

                return [
                    'success' => false,
                    'error' => 'Saldo insuficiente',
                    'previous_status' => $estadoAnterior,
                ];
            }

            try {
                // ELIMINADO: $this->info("   ✅ Saldo suficiente, procesando pago...");

                // Realizar el pago desde la wallet
                $wallet->makePayment($invoice->total_amount, "Pago automático - {$invoice->invoice_number}", "Pago de factura {$invoice->invoice_number}" . ($estadoAnterior === Invoice::STATUS_FAILED ? ' (Reintento)' : ''));

                // Obtener la transacción recién creada
                $transaction = $wallet->transactions()->latest()->first();

                // Marcar factura como pagada
                $invoice->markAsPaid(Invoice::PAYMENT_WALLET, $transaction->reference);

                // ELIMINADO: $this->info("   {$mensaje}");

                return [
                    'success' => true,
                    'message' => 'Pago procesado exitosamente',
                    'transaction_reference' => $transaction->reference,
                    'paid_at' => now()->toDateTimeString(),
                    'previous_status' => $estadoAnterior,
                    'is_retry' => $estadoAnterior === Invoice::STATUS_FAILED,
                ];
            } catch (\Exception $e) {
                // ELIMINADO: $this->error("   ❌ Error en pago: {$e->getMessage()}");
                $invoice->update(['status' => Invoice::STATUS_FAILED]);
                return [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'previous_status' => $estadoAnterior,
                ];
            }
        });
    }

    /**
     * Obtener resumen de facturación
     */
    public function getBillingSummary($clientId = null)
    {
        $query = Invoice::query();

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $totalInvoices = $query->count();
        $paidInvoices = $query->clone()->paid()->count();
        $pendingInvoices = $query->clone()->pending()->count();
        $overdueInvoices = $query->clone()->overdue()->count();
        $totalRevenue = $query->clone()->paid()->sum('total_amount');
        $pendingAmount = $query->clone()->pending()->sum('total_amount');

        return [
            'total_invoices' => $totalInvoices,
            'paid_invoices' => $paidInvoices,
            'pending_invoices' => $pendingInvoices,
            'overdue_invoices' => $overdueInvoices,
            'total_revenue' => $totalRevenue,
            'pending_amount' => $pendingAmount,
        ];
    }

    /**
     * Procesar facturación completa
     */
    public function processCompleteBilling()
    {
        $results = [];

        $results['invoices_generated'] = $this->generateMonthlyInvoices();
        $results['payments_processed'] = $this->processAutoPayments();
        $results['summary'] = $this->getBillingSummary();

        return $results;
    }
}
