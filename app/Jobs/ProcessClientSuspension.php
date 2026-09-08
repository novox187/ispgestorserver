<?php

namespace App\Jobs;

use App\Jobs\Concerns\NotifiesWorkerSummary;
use App\Models\AutomationSetting;
use App\Models\ClientServiceInterruption;
use App\Models\Invoice;
use App\Services\AutoBillingService;
use App\Services\ClientSuspensionService;
use App\Services\MikroTikService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessClientSuspension implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, NotifiesWorkerSummary;

    /** Intentos antes de enviar a failed_jobs */
    public int $tries = 3;

    /** Segundos entre reintentos */
    public int $backoff = 300;

    /** Segundos máximos de ejecución */
    public int $timeout = 120;

    public function handle(
        ClientSuspensionService $suspension,
        AutoBillingService $billing,
        MikroTikService $mikrotik,
    ): void
    {
        // Lectura fresca: bypass del cache para garantizar que un cambio
        // reciente en `enabled` se respete en el siguiente disparo del job.
        AutomationSetting::flushCache();
        $automation = AutomationSetting::getCached('client_suspension');

        $isEnabled = (bool) ($automation->enabled ?? false);

        Log::info('ProcessClientSuspension: estado de configuración al iniciar.', [
            'enabled'         => $isEnabled,
            'has_setting_row' => $automation !== null,
            'params'          => $automation?->params ?? [],
            'schedule_type'   => $automation?->schedule_type,
            'schedule_config' => $automation?->schedule_config,
            'last_run_at'     => $automation?->last_run_at?->toIso8601String(),
        ]);

        if (!$automation) {
            Log::warning('ProcessClientSuspension: no existe el registro AutomationSetting "client_suspension". Job abortado.');
            return;
        }

        if (!$isEnabled) {
            Log::info('ProcessClientSuspension: la Suspensión Automática de Clientes está DESACTIVADA. Ningún cliente será suspendido en esta ejecución.');
            return;
        }

        $graceDays = (int) AutomationSetting::getParam(
            'client_suspension',
            'grace_days',
            config('billing.suspension_grace_days', 3)
        );

        // updateQuietly evita generar entradas de auditoría por cada ejecución del job
        $automation->updateQuietly(['last_run_at' => now()]);

        // Los cobros automáticos son los que marcan una factura como `failed`.
        // Con esa automatización apagada, la cohorte de corte se vaciaba y NADIE
        // se suspendía nunca — indistinguible de una cartera sana en el log.
        // La cohorte ya no depende de ese estado (mira PENDING y FAILED), pero
        // el desajuste de configuración se avisa igualmente.
        $autoBillingEnabled = (bool) (AutomationSetting::getCached('auto_billing')?->enabled ?? false);

        if (!$autoBillingEnabled) {
            Log::warning('ProcessClientSuspension: los Cobros Automáticos están DESACTIVADOS. Ninguna factura pasará a estado failed; el corte opera solo por fecha de vencimiento.');
        }

        $overdueInvoices = Invoice::with(['client.wallet'])
            ->whereIn('status', [Invoice::STATUS_FAILED, Invoice::STATUS_PENDING])
            ->where('due_date', '<=', now()->subDays($graceDays)->toDateString())
            ->whereHas('client', function ($q) {
                $q->whereNotIn('service_status', [
                    'suspended', 'SUSPENDED', 'SUSPENDIDO',
                    'cancelled', 'CANCELLED',
                ]);

                // Excluir clientes con inclusión vigente en la lista blanca:
                // la validación final vive en ClientSuspensionService (defensa en
                // profundidad), pero filtramos aquí para evitar trabajo inútil.
                $q->whereDoesntHave('whitelistEntries', function ($w) {
                    $w->where('active', true)
                      ->where(function ($expiry) {
                          $expiry->whereNull('expires_at')
                                 ->orWhere('expires_at', '>', now());
                      });
                });
            })
            ->get();

        if ($overdueInvoices->isEmpty()) {
            Log::info('ProcessClientSuspension: Sin candidatos a suspender.');
            return;
        }

        // Un cliente con tres facturas vencidas generaba tres intentos de cobro
        // y tres llamadas al servicio de corte; solo la primera hacía algo. Se
        // agrupa por cliente y se procesa su factura más antigua, que es la que
        // justifica el corte.
        $candidates = $overdueInvoices
            ->sortBy('due_date')
            ->groupBy('client_id')
            ->map(fn ($invoices) => $invoices->first())
            ->values();

        $maxBatch = max(1, (int) config('billing.suspension_max_batch', 200));
        $totalCandidates = $candidates->count();
        $truncated = $totalCandidates > $maxBatch;

        if ($truncated) {
            // Cota de lote: el job tiene 120 s de timeout y cada cliente puede
            // costar dos viajes al router. Sin tope, una cartera vencida grande
            // mata la corrida a mitad y la reintenta entera desde el principio.
            Log::warning("ProcessClientSuspension: {$totalCandidates} candidatos exceden la cota de {$maxBatch}. Se procesan los {$maxBatch} más antiguos; el resto en la siguiente corrida.");
            $candidates = $candidates->take($maxBatch);
        }

        Log::info("ProcessClientSuspension: {$candidates->count()} cliente(s) a procesar con {$graceDays} día(s) de gracia cumplidos.");

        // Compuerta de salud del router: sin esta comprobación, una caída de
        // MikroTik convertía la corrida entera en cortes solo-BD — los clientes
        // dejaban de facturarse y seguían navegando, y el resumen lo daba por
        // bueno. Ante un router mudo es preferible no cortar a nadie.
        try {
            $routerHealthy = !empty($mikrotik->getSystemInfo());
        } catch (\Throwable $e) {
            Log::error('ProcessClientSuspension: excepción al comprobar la salud del router: ' . $e->getMessage());
            $routerHealthy = false;
        }

        if (!$routerHealthy) {
            Log::error('ProcessClientSuspension: MikroTik no responde. Corrida ABORTADA para no aplicar cortes que no surtirían efecto.');

            $this->notifyWorkerSummary(
                workerName: 'ProcessClientSuspension',
                result:     [
                    'errors'     => 1,
                    'aborted'    => 'MikroTik no responde',
                    'candidates' => $totalCandidates,
                    'suspended'  => 0,
                    'grace_days' => $graceDays,
                ],
                objective:  'Suspender servicios de clientes con facturas vencidas',
            );

            return;
        }

        $suspended  = 0;
        $recovered  = 0;
        $errors     = 0;
        $notCutInNetwork = 0;

        foreach ($candidates as $invoice) {
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
                } elseif ($result['whitelisted'] ?? false) {
                    Log::info("ProcessClientSuspension: Cliente {$client->id} protegido por lista blanca.");
                } else {
                    $suspended++;

                    // Corte aplicado en BD que no se pudo confirmar en la red:
                    // el caso que antes se reportaba como éxito.
                    $state = $result['enforcement_state'] ?? null;
                    if ($state !== null && in_array($state, ClientServiceInterruption::ENFORCEMENT_FAILED_STATES, true)) {
                        $notCutInNetwork++;
                    }
                }
            } catch (\Throwable $e) {
                // Loggear pero NO relanzar: un error en un cliente no debe detener al resto
                Log::error("ProcessClientSuspension: Error suspendiendo cliente {$client->id}: " . $e->getMessage());
                $errors++;
            }
        }

        $summary = [
            'suspended'  => $suspended,
            'recovered'  => $recovered,
            // Los cortes no confirmados en la red cuentan como error: es lo que
            // hace que el resumen escale a severidad crítica en vez de mandar
            // un ✅ mientras los clientes cortados siguen navegando.
            'errors'     => $errors + $notCutInNetwork,
            'cortes_sin_efecto_en_red' => $notCutInNetwork,
            'grace_days' => $graceDays,
        ];

        if (!$autoBillingEnabled) {
            $summary['aviso'] = 'Cobros Automáticos desactivados';
        }

        if ($truncated) {
            $summary['pendientes_siguiente_corrida'] = $totalCandidates - $maxBatch;
        }

        Log::info("ProcessClientSuspension finalizado. Suspendidos: {$suspended}, Recuperados: {$recovered}, Sin efecto en red: {$notCutInNetwork}, Errores: {$errors}.");

        $this->notifyWorkerSummary(
            workerName: 'ProcessClientSuspension',
            result:     $summary,
            objective:  'Suspender servicios de clientes con facturas vencidas',
        );
    }

    public function failed(Throwable $exception): void
    {
        $this->notifyWorkerFailure(
            workerName: 'ProcessClientSuspension',
            exception:  $exception,
            objective:  'Suspender servicios de clientes con facturas vencidas',
        );
    }
}
