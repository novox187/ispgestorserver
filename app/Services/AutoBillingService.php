<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Wallet;
use App\Services\SettingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
     * Generar facturas por cliente, ancladas a su fecha de contrato.
     *
     * Para cada cliente activo con contract_date no nula:
     *   - calcula el inicio del ciclo de facturación corriente (último aniversario
     *     del día-de-mes de contract_date que es <= hoy)
     *   - verifica si ya existe una factura para ese client_plan en ese ciclo
     *     (incluyendo papelera) usando lockForUpdate dentro de una transacción
     *   - si no existe, la crea; si existe, se omite con motivo registrado
     *
     * Cada factura se crea en su propia transacción atómica. Si ocurre una
     * excepción al crear una factura puntual, se aborta la iteración para evitar
     * acumulación de errores y se devuelve un reporte con los resultados parciales.
     */
    public function generateInvoicesByContractDate(): array
    {
        $settingService = app(SettingService::class);

        try {
            $snapshot = $settingService->buildInvoiceSnapshot();
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('billing')->error('Generación por fecha de contrato abortada: faltan configuraciones', [
                'errors' => $e->errors(),
            ]);
            throw $e;
        }

        $taxRate     = $settingService->taxRateFromSnapshot($snapshot);
        $executionId = (string) Str::uuid();
        $startedAt   = now();
        $employee    = auth()->user();

        Log::channel('billing')->info('Generación por fecha de contrato — INICIO', [
            'execution_id' => $executionId,
            'started_at'   => $startedAt->toIso8601String(),
            'employee_id'  => $employee?->id,
            'employee'     => $employee?->name,
        ]);

        $clientsWithPlans = Client::query()
            ->whereHas('clientPlans', fn ($q) => $q->where('status', 'active'))
            ->whereNotNull('contract_date')
            ->with(['clientPlans' => fn ($q) => $q->where('status', 'active'), 'clientPlans.plan'])
            ->orderBy('id')
            ->get();

        $processedClients = [];
        $generated        = [];
        $skipped          = [];
        $errors           = [];
        $aborted          = false;
        $abortReason      = null;

        foreach ($clientsWithPlans as $client) {
            $processedClients[] = [
                'client_id'     => $client->id,
                'full_name'     => $client->full_name,
                'contract_date' => optional($client->contract_date)->toDateString(),
                'plans_count'   => $client->clientPlans->count(),
            ];

            foreach ($client->clientPlans as $clientPlan) {
                try {
                    $result = DB::transaction(function () use ($client, $clientPlan, $snapshot, $taxRate) {
                        return $this->createContractBasedInvoice($client, $clientPlan, $snapshot, $taxRate);
                    });

                    if ($result['status'] === 'created') {
                        $generated[] = [
                            'invoice_id'     => $result['invoice']->id,
                            'invoice_number' => $result['invoice']->invoice_number,
                            'client_id'      => $client->id,
                            'client_plan_id' => $clientPlan->id,
                            'cycle_start'    => $result['cycle_start'],
                            'cycle_end'      => $result['cycle_end'],
                            'total_amount'   => $result['invoice']->total_amount,
                        ];
                    } else {
                        $skipped[] = $result;
                    }
                } catch (\Throwable $e) {
                    $errors[] = [
                        'client_id'      => $client->id,
                        'client_plan_id' => $clientPlan->id,
                        'reason'         => $e->getMessage(),
                    ];
                    $aborted     = true;
                    $abortReason = "Error en cliente #{$client->id} / plan #{$clientPlan->id}: {$e->getMessage()}";

                    Log::channel('billing')->error('Generación por fecha de contrato — abortada por error', [
                        'execution_id'   => $executionId,
                        'client_id'      => $client->id,
                        'client_plan_id' => $clientPlan->id,
                        'error'          => $e->getMessage(),
                        'trace'          => $e->getTraceAsString(),
                    ]);
                    break 2;
                }
            }
        }

        $report = [
            'execution_id'      => $executionId,
            'started_at'        => $startedAt->toIso8601String(),
            'finished_at'       => now()->toIso8601String(),
            'aborted'           => $aborted,
            'abort_reason'      => $abortReason,
            'clients_total'     => count($processedClients),
            'generated_count'   => count($generated),
            'skipped_count'     => count($skipped),
            'errors_count'      => count($errors),
            'processed_clients' => $processedClients,
            'generated'         => $generated,
            'skipped'           => $skipped,
            'errors'            => $errors,
        ];

        Log::channel('billing')->info('Generación por fecha de contrato — FIN', $report);

        return $report;
    }

    /**
     * Crear una factura anclada a la fecha de contrato del cliente.
     * Debe invocarse dentro de una transacción con lockForUpdate para garantizar
     * que la verificación de duplicado y la inserción sean atómicas.
     */
    private function createContractBasedInvoice(Client $client, $clientPlan, array $snapshot, float $taxRate): array
    {
        $contractDate = $client->contract_date;
        if (!$contractDate) {
            return [
                'status'         => 'skipped',
                'reason'         => 'cliente sin fecha de contrato',
                'client_id'      => $client->id,
                'client_plan_id' => $clientPlan->id,
            ];
        }

        // El precio actual es indispensable para emitir factura.
        $amount = (float) ($clientPlan->current_price ?? optional($clientPlan->plan)->monthly_price ?? 0);
        if ($amount <= 0) {
            return [
                'status'         => 'skipped',
                'reason'         => 'plan sin precio válido',
                'client_id'      => $client->id,
                'client_plan_id' => $clientPlan->id,
            ];
        }

        [$cycleStart, $cycleEnd] = $this->computeContractCycle($contractDate);

        // Bloqueo pesimista para evitar duplicados ante ejecuciones concurrentes.
        // Se incluyen las facturas con soft-delete para no reutilizar números/fechas.
        $existing = Invoice::withTrashed()
            ->where('client_id', $client->id)
            ->where('client_plan_id', $clientPlan->id)
            ->whereBetween('issue_date', [$cycleStart->toDateString(), $cycleEnd->toDateString()])
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return [
                'status'                  => 'skipped',
                'reason'                  => 'ya existe una factura para este ciclo',
                'existing_invoice_id'     => $existing->id,
                'existing_invoice_number' => $existing->invoice_number,
                'client_id'               => $client->id,
                'client_plan_id'          => $clientPlan->id,
                'cycle_start'             => $cycleStart->toDateString(),
                'cycle_end'               => $cycleEnd->toDateString(),
            ];
        }

        $taxAmount   = round($amount * $taxRate, 2);
        $totalAmount = round($amount + $taxAmount, 2);

        $invoice = Invoice::create([
            'client_id'              => $client->id,
            'client_plan_id'         => $clientPlan->id,
            'invoice_number'         => Invoice::generateInvoiceNumber(),
            'issue_date'             => $cycleStart,
            'due_date'               => $cycleStart->copy()->addDays(15),
            'amount'                 => $amount,
            'tax_amount'             => $taxAmount,
            'total_amount'           => $totalAmount,
            'status'                 => Invoice::STATUS_PENDING,
            'description'            => "Factura por contrato — " . optional($clientPlan->plan)->name . " (" . $cycleStart->format('Y-m-d') . ")",
            'configuration_snapshot' => $snapshot,
            'metadata'               => [
                'billing_cycle'   => 'contract_based',
                'plan_name'       => optional($clientPlan->plan)->name,
                'contract_date'   => $contractDate->toDateString(),
                'cycle_start'     => $cycleStart->toDateString(),
                'cycle_end'       => $cycleEnd->toDateString(),
                'download_speed'  => optional($clientPlan->plan)->download_speed,
                'upload_speed'    => optional($clientPlan->plan)->upload_speed,
            ],
        ]);

        return [
            'status'      => 'created',
            'invoice'     => $invoice,
            'cycle_start' => $cycleStart->toDateString(),
            'cycle_end'   => $cycleEnd->toDateString(),
        ];
    }

    /**
     * Calcula el rango del ciclo de facturación corriente a partir del día-de-mes
     * de la fecha de contrato. El inicio del ciclo es el aniversario más reciente
     * <= hoy; el fin es un día antes del siguiente aniversario.
     *
     * Considera meses cortos: si contract_date.day=31 y el mes solo tiene 30,
     * usa el último día del mes.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function computeContractCycle(Carbon $contractDate): array
    {
        $today       = Carbon::now()->startOfDay();
        $contractDay = (int) $contractDate->day;

        $thisMonthAnniversary = $today->copy()->startOfMonth()
            ->addDays(min($contractDay, $today->copy()->endOfMonth()->day) - 1);

        $cycleStart = $thisMonthAnniversary->lte($today)
            ? $thisMonthAnniversary
            : $today->copy()->subMonthNoOverflow()->startOfMonth()
                ->addDays(min($contractDay, $today->copy()->subMonthNoOverflow()->endOfMonth()->day) - 1);

        $nextAnchorMonth = $cycleStart->copy()->addMonthNoOverflow()->startOfMonth();
        $nextAnchor      = $nextAnchorMonth->addDays(min($contractDay, $nextAnchorMonth->copy()->endOfMonth()->day) - 1);

        $cycleEnd = $nextAnchor->copy()->subDay()->endOfDay();

        return [$cycleStart->startOfDay(), $cycleEnd];
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
