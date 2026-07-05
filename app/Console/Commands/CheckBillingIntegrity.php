<?php

namespace App\Console\Commands;

use App\Services\BillingIntegrityService;
use Illuminate\Console\Command;

/**
 * Ejecución manual de la conciliación de integridad (el worker automático es
 * ReconcileBillingIntegrity vía AutomationSettings). Útil para verificar el
 * estado tras un deploy o una corrección manual de datos.
 */
class CheckBillingIntegrity extends Command
{
    protected $signature = 'billing:check-integrity
                            {--skip-mikrotik : Omitir la comparación contra la lista morosos del router}';

    protected $description = 'Verifica los invariantes entre facturación, cortes de servicio y MikroTik (solo lectura)';

    public function handle(BillingIntegrityService $integrity): int
    {
        $this->info('🔍 Conciliación de integridad de facturación y cortes...');
        $this->line('Fecha y hora: ' . now()->toDateTimeString());
        $this->line('');

        $report = $integrity->reconcile(!$this->option('skip-mikrotik'));

        $rows = [
            ['Estado facturable con corte abierto', count($report['findings']['active_with_open_cut'])],
            ['Cortado sin ventana abierta',         count($report['findings']['cut_without_open_window'])],
            ['Facturas emitidas durante un corte',  count($report['findings']['invoices_issued_during_cut'])],
            ['Cortado con planes activos',          count($report['findings']['cut_clients_with_active_plans'])],
            ['Suspendidos fuera de morosos',        count($report['findings']['mikrotik']['suspended_not_in_morosos'] ?? [])],
            ['En morosos sin estar suspendidos',    count($report['findings']['mikrotik']['in_morosos_not_suspended'] ?? [])],
        ];
        $this->table(['Invariante', 'Hallazgos'], $rows);

        if ($skipped = ($report['findings']['mikrotik']['skipped'] ?? null)) {
            $this->warn("Chequeo MikroTik omitido: {$skipped}");
        }

        if ($report['healthy']) {
            $this->info('✅ Sin inconsistencias.');
            return self::SUCCESS;
        }

        $this->warn("⚠️  {$report['total_findings']} inconsistencia(s). Detalle en el log 'billing' y en la auditoría (BILLING_INTEGRITY_OP).");
        $this->line(json_encode($report['findings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::FAILURE;
    }
}
