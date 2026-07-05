<?php

namespace App\Jobs;

use App\Jobs\Concerns\NotifiesWorkerSummary;
use App\Models\AutomationSetting;
use App\Services\BillingIntegrityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Worker de conciliación: verifica los invariantes entre facturación, cortes
 * de servicio y MikroTik, y notifica las inconsistencias encontradas.
 *
 * SOLO LECTURA: no corrige nada automáticamente; el detalle queda en el log
 * 'billing', en la auditoría (BILLING_INTEGRITY_OP) y en el resumen del worker.
 */
class ReconcileBillingIntegrity implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, NotifiesWorkerSummary;

    public int $tries   = 2;
    public int $timeout = 180;

    public function handle(BillingIntegrityService $integrity): void
    {
        // Lectura fresca: bypass del cache para que un cambio reciente en
        // `enabled` se respete en el siguiente disparo del job.
        AutomationSetting::flushCache();
        $automation = AutomationSetting::getCached('billing_integrity');

        if (!$automation) {
            Log::warning('ReconcileBillingIntegrity: no existe el registro AutomationSetting "billing_integrity". Job abortado.');
            return;
        }

        if (!(bool) ($automation->enabled ?? false)) {
            Log::info('ReconcileBillingIntegrity: la Conciliación de Integridad está DESACTIVADA. Nada que verificar en esta ejecución.');
            return;
        }

        $checkMikrotik = (bool) AutomationSetting::getParam('billing_integrity', 'check_mikrotik', true);

        // updateQuietly evita generar entradas de auditoría por cada ejecución
        $automation->updateQuietly(['last_run_at' => now()]);

        $report = $integrity->reconcile($checkMikrotik);

        Log::info('ReconcileBillingIntegrity finalizado.', [
            'healthy'        => $report['healthy'],
            'total_findings' => $report['total_findings'],
        ]);

        $this->notifyWorkerSummary(
            workerName: 'ReconcileBillingIntegrity',
            result:     [
                'healthy'        => $report['healthy'],
                'total_findings' => $report['total_findings'],
                'counts'         => [
                    'estado_facturable_con_corte_abierto' => count($report['findings']['active_with_open_cut']),
                    'cortado_sin_ventana_abierta'         => count($report['findings']['cut_without_open_window']),
                    'facturas_emitidas_durante_corte'     => count($report['findings']['invoices_issued_during_cut']),
                    'cortado_con_planes_activos'          => count($report['findings']['cut_clients_with_active_plans']),
                    'suspendidos_fuera_de_morosos'        => count($report['findings']['mikrotik']['suspended_not_in_morosos'] ?? []),
                    'en_morosos_sin_estar_suspendidos'    => count($report['findings']['mikrotik']['in_morosos_not_suspended'] ?? []),
                ],
                'mikrotik_skipped' => $report['findings']['mikrotik']['skipped'] ?? null,
            ],
            objective:  'Detectar inconsistencias entre facturación, cortes de servicio y MikroTik',
        );
    }

    public function failed(Throwable $exception): void
    {
        $this->notifyWorkerFailure(
            workerName: 'ReconcileBillingIntegrity',
            exception:  $exception,
            objective:  'Detectar inconsistencias entre facturación, cortes de servicio y MikroTik',
        );
    }
}
