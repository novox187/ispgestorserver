<?php

namespace App\Services;

use App\Models\Audit;
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
        $dueDays = $settingService->invoiceDueDaysFromSnapshot($snapshot);

        // Clientes a facturar este mes:
        //   - cualquiera con al menos un plan ACTIVO, o
        //   - clientes protegidos por la lista blanca que tengan planes SUSPENDIDOS.
        //
        // La lista blanca solo evita el corte del servicio; NO debe detener la
        // facturación. Un cliente puede haber sido suspendido (sus planes pasan a
        // 'suspended') ANTES de entrar a la lista blanca, y entrar a la lista no
        // reactiva esos planes. Sin esta segunda condición esos clientes quedaban
        // fuera del generador y nunca se les emitía factura.
        $clientsWithPlans = Client::query()
            ->where(function ($q) {
                $q->whereHas('clientPlans', fn ($p) => $p->where('status', 'active'))
                  ->orWhere(function ($w) {
                      $w->whereHas('whitelistEntries', fn ($we) => $we->active())
                        ->whereHas('clientPlans', fn ($p) => $p->where('status', 'suspended'));
                  });
            })
            ->with([
                'clientPlans.plan',
                'whitelistEntries' => fn ($we) => $we->active(),
            ])
            ->get();

        $generatedInvoices = [];

        foreach ($clientsWithPlans as $client) {
            $isWhitelisted = $client->whitelistEntries->isNotEmpty();

            foreach ($client->clientPlans as $clientPlan) {
                // Se facturan los planes ACTIVOS siempre. Los SUSPENDIDOS solo se
                // facturan cuando el cliente está en la lista blanca (servicio
                // protegido: se le sigue cobrando). Los planes 'cancelled' o
                // 'pending' nunca se facturan en este ciclo.
                $billable = $clientPlan->status === 'active'
                    || ($isWhitelisted && $clientPlan->status === 'suspended');

                if (!$billable) {
                    continue;
                }

                $invoice = $this->createMonthlyInvoice($client, $clientPlan, $snapshot, $taxRate, $dueDays);
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
    private function createMonthlyInvoice(Client $client, $clientPlan, array $snapshot, float $taxRate, int $dueDays): ?Invoice
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

        $issueDate = now();
        $dueDate   = $issueDate->copy()->addDays($dueDays);

        $invoice = Invoice::create([
            'client_id'              => $client->id,
            'client_plan_id'         => $clientPlan->id,
            'invoice_number'         => Invoice::generateInvoiceNumber(),
            'issue_date'             => $issueDate,
            'due_date'               => $dueDate,
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

        $this->logDueDateCalculation($invoice, $issueDate, $dueDays, $dueDate, $snapshot, 'monthly');

        return $invoice;
    }

    /**
     * Registra en auditoría el desglose del cálculo de la fecha de vencimiento.
     * Pista de trazabilidad pedida por negocio: emisión → plazo configurado → vencimiento calculado.
     */
    private function logDueDateCalculation(
        Invoice $invoice,
        \Carbon\Carbon|\DateTimeInterface $issueDate,
        int $dueDays,
        \Carbon\Carbon|\DateTimeInterface $dueDate,
        array $snapshot,
        string $cycle
    ): void {
        $issue = \Carbon\Carbon::instance($issueDate);
        $due   = \Carbon\Carbon::instance($dueDate);

        // Si el setting estaba ausente del snapshot, lo marcamos como "fallback".
        $source = array_key_exists('invoice_due_days', $snapshot) ? 'system_settings' : 'fallback_15';

        Audit::create([
            'table_name' => 'invoices',
            'operation'  => 'INVOICE_DUE_CALC_OP',
            'record_id'  => (string) $invoice->id,
            'old_values' => null,
            'new_values' => [
                'invoice_number'           => $invoice->invoice_number,
                'issue_date'               => $issue->toDateString(),
                'invoice_due_days'         => $dueDays,
                'invoice_due_days_source'  => $source,
                'due_date'                 => $due->toDateString(),
                'billing_cycle'            => $cycle,
                'computed_at'              => now()->toIso8601String(),
            ],
            'user_id'    => null,
            'ip_address' => '127.0.0.1',
        ]);
    }

     /**
    * Límite estricto para evitar bucles descontrolados si un cliente tiene una
    * contract_date en un pasado remoto (p. ej., datos inconsistentes). 
    * 240 ciclos ≈ 20 años de facturación mensual. 
    */
    private const MAX_CYCLES_PER_CLIENT = 240;

    /**
     * Generar facturas retroactivas por cliente, ancladas a su fecha de contrato.
     *
     * Para cada cliente activo con contract_date no nula:
     *   - calcula TODOS los ciclos desde contract_date hasta hoy
     *   - para cada ciclo: si ya existe factura en ese rango → omite; si no → crea
     *
     * Cada ciclo se evalúa en su propia transacción con lockForUpdate. Si una
     * creación falla, se registra el error y se continúa con el siguiente cliente
     * (los demás ciclos del cliente actual se omiten para evitar inconsistencias).
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
        $dueDays     = $settingService->invoiceDueDaysFromSnapshot($snapshot);
        $executionId = (string) Str::uuid();
        $startedAt   = now();
        $employee    = auth()->user();

        Log::channel('billing')->info('Generación por fecha de contrato — INICIO', [
            'execution_id' => $executionId,
            'started_at'   => $startedAt->toIso8601String(),
            'employee_id'  => $employee?->id,
            'employee'     => $employee?->name,
        ]);

        // Mismo criterio que la generación mensual: además de los clientes con
        // planes activos, se incluyen los protegidos por la lista blanca con
        // planes suspendidos (la lista blanca no detiene la facturación, solo el
        // corte del servicio). Los planes suspendidos se cargan para todos los
        // candidatos y se filtran por whitelist dentro del bucle.
        $clientsWithPlans = Client::query()
            ->whereNotNull('contract_date')
            ->where(function ($q) {
                $q->whereHas('clientPlans', fn ($p) => $p->where('status', 'active'))
                  ->orWhere(function ($w) {
                      $w->whereHas('whitelistEntries', fn ($we) => $we->active())
                        ->whereHas('clientPlans', fn ($p) => $p->where('status', 'suspended'));
                  });
            })
            ->with([
                'clientPlans' => fn ($q) => $q->whereIn('status', ['active', 'suspended']),
                'clientPlans.plan',
                'whitelistEntries' => fn ($we) => $we->active(),
            ])
            ->orderBy('id')
            ->get();

        $processedClients = [];
        $generated        = [];
        $skipped          = [];
        $errors           = [];

        foreach ($clientsWithPlans as $client) {
            $isWhitelisted = $client->whitelistEntries->isNotEmpty();

            // Planes facturables: activos siempre; suspendidos solo si el cliente
            // está protegido por la lista blanca.
            $billablePlans = $client->clientPlans->filter(
                fn ($cp) => $cp->status === 'active'
                    || ($isWhitelisted && $cp->status === 'suspended')
            );

            $cycles = $this->computeAllCyclesFromContract($client->contract_date);

            $processedClients[] = [
                'client_id'     => $client->id,
                'full_name'     => $client->full_name,
                'contract_date' => optional($client->contract_date)->toDateString(),
                'plans_count'   => $billablePlans->count(),
                'cycles_total'  => count($cycles),
            ];

            foreach ($billablePlans as $clientPlan) {
                $clientPlanAborted = false;

                foreach ($cycles as [$cycleStart, $cycleEnd]) {
                    if ($clientPlanAborted) {
                        break;
                    }

                    try {
                        $result = DB::transaction(function () use ($client, $clientPlan, $snapshot, $taxRate, $dueDays, $cycleStart, $cycleEnd) {
                            return $this->createContractBasedInvoice($client, $clientPlan, $snapshot, $taxRate, $dueDays, $cycleStart, $cycleEnd);
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
                            'cycle_start'    => $cycleStart->toDateString(),
                            'cycle_end'      => $cycleEnd->toDateString(),
                            'reason'         => $e->getMessage(),
                        ];

                        Log::channel('billing')->error('Generación por fecha de contrato — error en ciclo', [
                            'execution_id'   => $executionId,
                            'client_id'      => $client->id,
                            'client_plan_id' => $clientPlan->id,
                            'cycle_start'    => $cycleStart->toDateString(),
                            'cycle_end'      => $cycleEnd->toDateString(),
                            'error'          => $e->getMessage(),
                        ]);

                        // Detener este client_plan, pero seguir con otros clientes.
                        $clientPlanAborted = true;
                    }
                }
            }
        }

        $report = [
            'execution_id'      => $executionId,
            'started_at'        => $startedAt->toIso8601String(),
            'finished_at'       => now()->toIso8601String(),
            'aborted'           => false,
            'abort_reason'      => null,
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
     * Crear una factura para un ciclo específico anclado a la fecha de contrato.
     * Debe invocarse dentro de una transacción con lockForUpdate para garantizar
     * que la verificación de duplicado y la inserción sean atómicas.
     */
    private function createContractBasedInvoice(Client $client, $clientPlan, array $snapshot, float $taxRate, int $dueDays, Carbon $cycleStart, Carbon $cycleEnd): array
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

        $dueDate = $cycleStart->copy()->addDays($dueDays);

        $invoice = Invoice::create([
            'client_id'              => $client->id,
            'client_plan_id'         => $clientPlan->id,
            'invoice_number'         => Invoice::generateInvoiceNumber(),
            'issue_date'             => $cycleStart,
            'due_date'               => $dueDate,
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

        $this->logDueDateCalculation($invoice, $cycleStart, $dueDays, $dueDate, $snapshot, 'contract_based');

        return [
            'status'      => 'created',
            'invoice'     => $invoice,
            'cycle_start' => $cycleStart->toDateString(),
            'cycle_end'   => $cycleEnd->toDateString(),
        ];
    }

    /**
     * Calcula TODOS los ciclos desde contract_date hasta hoy (inclusive del ciclo actual).
     *
     * Cada ciclo es un par [start, end] donde:
     *   - start es el aniversario del día-de-mes de contract_date para ese mes
     *   - end es un día antes del siguiente aniversario
     *
     * Maneja meses cortos: si contract_date.day=31 y un mes solo tiene 30 días,
     * el aniversario se ajusta al último día del mes.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    private function computeAllCyclesFromContract(Carbon $contractDate): array
    {
        $today       = Carbon::now()->startOfDay();
        $contractDay = (int) $contractDate->day;

        $cycles = [];
        $cursor = $contractDate->copy()->startOfDay();

        // Si el contract_date es futuro, no hay ciclos a generar.
        if ($cursor->gt($today)) {
            return [];
        }

        $iteration = 0;
        while ($cursor->lte($today) && $iteration < self::MAX_CYCLES_PER_CLIENT) {
            $cycleStart = $cursor->copy();

            $nextAnchorMonth = $cursor->copy()->addMonthNoOverflow()->startOfMonth();
            $nextAnchor      = $nextAnchorMonth->copy()
                ->addDays(min($contractDay, $nextAnchorMonth->copy()->endOfMonth()->day) - 1);

            $cycleEnd = $nextAnchor->copy()->subDay()->endOfDay();

            $cycles[] = [$cycleStart, $cycleEnd];
            $cursor   = $nextAnchor;
            $iteration++;
        }

        return $cycles;
    }

    /**
     * Parámetros por defecto del proceso de cobros automáticos.
     * Se utilizan cuando no se proveen vía AutomationSetting (p. ej. ejecución manual).
     */
    public const AUTO_BILLING_DEFAULTS = [
        'days_before_due'    => 5,    // ventana de anticipación (días antes del vencimiento)
        'retry_window_days'  => 7,    // ventana de reintento para facturas FAILED recientes
        'max_retries'        => 3,    // tope de reintentos por factura
        'min_amount'         => 0.50, // monto mínimo cobrable
        'max_amount'         => 10000.00, // tope de seguridad por cobro
        'notify_on_success'  => false,
        'notify_on_failure'  => true,
        'notify_on_retry'    => false,
    ];

    /**
     * Procesar pagos automáticos.
     *
     * @param  array  $config  Parámetros operativos. Cualquier ausente se completa con AUTO_BILLING_DEFAULTS.
     *                         Claves soportadas:
     *                           - days_before_due:    int
     *                           - retry_window_days:  int
     *                           - max_retries:        int
     *                           - min_amount:         float
     *                           - max_amount:         float
     *                           - notify_on_success:  bool
     *                           - notify_on_failure:  bool
     *                           - notify_on_retry:    bool
     */
    public function processAutoPayments(array $config = []): array
    {
        $config = array_merge(self::AUTO_BILLING_DEFAULTS, $config);

        // Facturas elegibles para cobro/reintento:
        //   - PENDING o FAILED próximas a vencer (dentro de days_before_due)
        //   - FAILED recientes (creadas dentro de retry_window_days) para reintento
        $invoices = Invoice::with(['client.wallet'])
            ->where(function ($query) use ($config) {
                $query->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_FAILED])
                      ->where('due_date', '<=', now()->addDays((int) $config['days_before_due']));
            })
            ->orWhere(function ($query) use ($config) {
                $query->where('status', Invoice::STATUS_FAILED)
                      ->where('created_at', '>=', now()->subDays((int) $config['retry_window_days']));
            })
            ->get();

        $results            = [];
        $reintentosExitosos = 0;
        $skippedRange       = 0;
        $skippedMaxRetries  = 0;

        foreach ($invoices as $invoice) {
            $amount = (float) $invoice->total_amount;

            // Filtro por montos mínimo/máximo configurados
            if ($amount < (float) $config['min_amount'] || $amount > (float) $config['max_amount']) {
                $skippedRange++;
                $results[] = [
                    'invoice_id'      => $invoice->id,
                    'invoice_number'  => $invoice->invoice_number,
                    'client_id'       => $invoice->client_id,
                    'previous_status' => $invoice->status,
                    'result'          => [
                        'success' => false,
                        'skipped' => true,
                        'error'   => "Monto {$amount} fuera del rango [{$config['min_amount']}, {$config['max_amount']}]",
                    ],
                ];
                continue;
            }

            // Filtro por máximo de reintentos en facturas FAILED
            $retryCount = (int) ($invoice->metadata['retry_count'] ?? 0);
            if ($invoice->status === Invoice::STATUS_FAILED && $retryCount >= (int) $config['max_retries']) {
                $skippedMaxRetries++;
                $results[] = [
                    'invoice_id'      => $invoice->id,
                    'invoice_number'  => $invoice->invoice_number,
                    'client_id'       => $invoice->client_id,
                    'previous_status' => $invoice->status,
                    'result'          => [
                        'success' => false,
                        'skipped' => true,
                        'error'   => "Reintentos agotados ({$retryCount}/{$config['max_retries']})",
                    ],
                ];
                continue;
            }

            $estadoAnterior = $invoice->status;
            $result         = $this->processInvoicePayment($invoice);

            $results[] = [
                'invoice_id'      => $invoice->id,
                'invoice_number'  => $invoice->invoice_number,
                'client_id'       => $invoice->client_id,
                'previous_status' => $estadoAnterior,
                'result'          => $result,
            ];

            // Aplicar reglas de notificación según el resultado
            if ($result['success']) {
                if ($estadoAnterior === Invoice::STATUS_FAILED) {
                    $reintentosExitosos++;
                    if ($config['notify_on_retry']) {
                        Log::channel('billing')->info("AutoBilling: REINTENTO exitoso para {$invoice->invoice_number}.");
                    }
                } elseif ($config['notify_on_success']) {
                    Log::channel('billing')->info("AutoBilling: pago exitoso para {$invoice->invoice_number}.");
                }
            } else {
                // Incrementar contador de reintentos cuando el fallo NO es por skip
                if ($invoice->status === Invoice::STATUS_FAILED) {
                    $invoice->update([
                        'metadata' => array_merge($invoice->metadata ?? [], [
                            'retry_count' => $retryCount + 1,
                            'last_retry_at' => now()->toIso8601String(),
                        ]),
                    ]);
                }
                if ($config['notify_on_failure']) {
                    Log::channel('billing')->warning("AutoBilling: fallo de cobro en {$invoice->invoice_number}: " . ($result['error'] ?? 'desconocido'));
                }
            }
        }

        return [
            'processed'           => $results,
            'retried_successfully'=> $reintentosExitosos,
            'skipped_amount'      => $skippedRange,
            'skipped_max_retries' => $skippedMaxRetries,
            'config_used'         => $config,
        ];
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
        $paymentRun = $this->processAutoPayments();
        $results['payments_processed'] = $paymentRun['processed'] ?? [];
        $results['summary'] = $this->getBillingSummary();

        return $results;
    }
}
