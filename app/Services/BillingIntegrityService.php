<?php

namespace App\Services;

use App\Models\Audit;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Conciliación de invariantes entre facturación, cortes de servicio y MikroTik.
 *
 * Este servicio es de SOLO LECTURA: detecta y reporta inconsistencias, nunca
 * las corrige automáticamente. La corrección es una decisión del operador
 * (con su propia trazabilidad); aquí solo se garantiza que las inconsistencias
 * se descubran horas después de producirse y no meses después en una factura
 * errónea.
 *
 * Invariantes verificados:
 *   1. Un cliente con estado facturable no debe tener ventana de corte abierta.
 *   2. Un cliente suspendido/cancelado debe tener ventana de corte abierta.
 *   3. Ninguna factura (no anulada) debe tener fecha de emisión dentro de una
 *      ventana de corte (misma regla de día que usa AutoBillingService).
 *   4. Un cliente suspendido/cancelado no debe conservar planes 'active'.
 *   5. (best-effort) La lista 'morosos' de MikroTik debe coincidir con los
 *      clientes suspendidos en BD, en ambas direcciones.
 */
class BillingIntegrityService
{
    /** Estados de servicio que implican corte (variantes ES/EN por datos legados). */
    private const CUT_STATUSES = ['SUSPENDED', 'SUSPENDIDO', 'CANCELLED', 'CANCELADO'];

    /** Tope de detalle por hallazgo para no inflar notificaciones/auditoría. */
    private const MAX_DETAILS_PER_FINDING = 50;

    /**
     * Ejecuta todos los chequeos y devuelve el reporte.
     *
     * @param  bool  $checkMikrotik  Incluir la comparación contra la lista 'morosos'.
     */
    public function reconcile(bool $checkMikrotik = true): array
    {
        $findings = [
            'active_with_open_cut'        => $this->activeClientsWithOpenCut(),
            'cut_without_open_window'     => $this->cutClientsWithoutOpenWindow(),
            'invoices_issued_during_cut'  => $this->invoicesIssuedDuringCut(),
            'cut_clients_with_active_plans' => $this->cutClientsWithActivePlans(),
            'mikrotik'                    => $checkMikrotik
                ? $this->mikrotikMorososMismatch()
                : ['skipped' => 'deshabilitado por parámetro'],
        ];

        $totalFindings =
            count($findings['active_with_open_cut']) +
            count($findings['cut_without_open_window']) +
            count($findings['invoices_issued_during_cut']) +
            count($findings['cut_clients_with_active_plans']) +
            count($findings['mikrotik']['suspended_not_in_morosos'] ?? []) +
            count($findings['mikrotik']['in_morosos_not_suspended'] ?? []);

        $report = [
            'checked_at'     => now()->toIso8601String(),
            'healthy'        => $totalFindings === 0,
            'total_findings' => $totalFindings,
            'findings'       => $findings,
        ];

        if ($totalFindings > 0) {
            $this->auditFindings($report);
            Log::channel('billing')->warning('Conciliación de integridad: se detectaron inconsistencias.', [
                'total_findings' => $totalFindings,
                'counts'         => $this->findingCounts($findings),
            ]);
        } else {
            Log::channel('billing')->info('Conciliación de integridad: sin inconsistencias.');
        }

        return $report;
    }

    /**
     * Invariante 1: estado facturable pero con ventana de corte abierta.
     * La facturación NO le emitirá facturas (la fecha límite manda), pero el
     * estado debe corregirse para que suspensión/reactivación operen bien.
     */
    private function activeClientsWithOpenCut(): array
    {
        return Client::query()
            ->whereNotIn(DB::raw('UPPER(service_status)'), self::CUT_STATUSES)
            ->whereHas('serviceInterruptions', fn ($q) => $q->whereNull('reactivated_at'))
            ->with(['serviceInterruptions' => fn ($q) => $q->whereNull('reactivated_at')])
            ->limit(self::MAX_DETAILS_PER_FINDING)
            ->get(['id', 'full_name', 'service_status'])
            ->map(fn (Client $c) => [
                'client_id'      => $c->id,
                'full_name'      => $c->full_name,
                'service_status' => $c->service_status,
                'cut_since'      => $c->serviceInterruptions->first()?->suspended_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Invariante 2: suspendido/cancelado sin ventana de corte abierta.
     * Típico de un mass-update o edición directa en BD que esquivó el observer:
     * la facturación lo excluye por estado, pero no hay fecha límite registrada.
     */
    private function cutClientsWithoutOpenWindow(): array
    {
        return Client::query()
            ->whereIn(DB::raw('UPPER(service_status)'), self::CUT_STATUSES)
            ->whereDoesntHave('serviceInterruptions', fn ($q) => $q->whereNull('reactivated_at'))
            ->limit(self::MAX_DETAILS_PER_FINDING)
            ->get(['id', 'full_name', 'service_status', 'updated_at'])
            ->map(fn (Client $c) => [
                'client_id'      => $c->id,
                'full_name'      => $c->full_name,
                'service_status' => $c->service_status,
                'status_since'   => $c->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Invariante 3: facturas emitidas dentro de una ventana de corte.
     * Misma regla de día que AutoBillingService: el día del corte no es
     * facturable, el día de la reactivación sí. Se excluyen las anuladas.
     */
    private function invoicesIssuedDuringCut(): array
    {
        return Invoice::query()
            ->join('client_service_interruptions as csi', 'csi.client_id', '=', 'invoices.client_id')
            ->where('invoices.status', '!=', Invoice::STATUS_CANCELLED)
            ->whereRaw('DATE(invoices.issue_date) >= DATE(csi.suspended_at)')
            ->where(function ($q) {
                $q->whereNull('csi.reactivated_at')
                  ->orWhereRaw('DATE(invoices.issue_date) < DATE(csi.reactivated_at)');
            })
            ->limit(self::MAX_DETAILS_PER_FINDING)
            ->get([
                'invoices.id',
                'invoices.invoice_number',
                'invoices.client_id',
                'invoices.issue_date',
                'invoices.status',
                'csi.id as interruption_id',
                'csi.suspended_at',
                'csi.reactivated_at',
            ])
            ->map(fn ($row) => [
                'invoice_id'      => $row->id,
                'invoice_number'  => $row->invoice_number,
                'client_id'       => $row->client_id,
                'issue_date'      => \Illuminate\Support\Carbon::parse($row->issue_date)->toDateString(),
                'invoice_status'  => $row->status,
                'interruption_id' => $row->interruption_id,
                'cut_window'      => [
                    'suspended_at'   => \Illuminate\Support\Carbon::parse($row->suspended_at)->toDateString(),
                    'reactivated_at' => $row->reactivated_at
                        ? \Illuminate\Support\Carbon::parse($row->reactivated_at)->toDateString()
                        : null,
                ],
            ])
            ->all();
    }

    /**
     * Invariante 4: cliente cortado con planes aún 'active'.
     * Si además el estado del cliente se corrigiera mal, esos planes volverían
     * a ser elegibles para facturación.
     */
    private function cutClientsWithActivePlans(): array
    {
        return Client::query()
            ->whereIn(DB::raw('UPPER(service_status)'), self::CUT_STATUSES)
            ->whereHas('clientPlans', fn ($q) => $q->where('status', 'active'))
            ->withCount(['clientPlans as active_plans_count' => fn ($q) => $q->where('status', 'active')])
            ->limit(self::MAX_DETAILS_PER_FINDING)
            ->get(['id', 'full_name', 'service_status'])
            ->map(fn (Client $c) => [
                'client_id'          => $c->id,
                'full_name'          => $c->full_name,
                'service_status'     => $c->service_status,
                'active_plans_count' => $c->active_plans_count,
            ])
            ->all();
    }

    /**
     * Invariante 5 (best-effort): la lista 'morosos' del router debe reflejar a
     * los clientes SUSPENDIDOS con IP. Si MikroTik está deshabilitado o no
     * responde, el chequeo se omite sin marcar inconsistencia (queda anotado).
     */
    private function mikrotikMorososMismatch(): array
    {
        if (!config('mikrotik.enabled')) {
            return ['skipped' => 'mikrotik deshabilitado en configuración'];
        }

        try {
            $mikrotik = app(MikroTikService::class);
            $result   = $mikrotik->getAddressListEntries('morosos');
        } catch (\Throwable $e) {
            return ['skipped' => 'MikroTik no disponible: ' . $e->getMessage()];
        }

        if (!($result['success'] ?? false)) {
            return ['skipped' => 'MikroTik no disponible: ' . ($result['message'] ?? 'error desconocido')];
        }

        $morososIps = collect($result['entries'])->pluck('address')->unique();

        // Suspendidos (no cancelados: la baja libera recursos por otra vía) con
        // IP real que NO están bloqueados en el router → siguen navegando.
        $suspendedNotInList = Client::query()
            ->whereIn(DB::raw('UPPER(service_status)'), ['SUSPENDED', 'SUSPENDIDO'])
            ->whereNotNull('ip')
            ->where('ip', '!=', '0.0.0.0')
            ->whereNotIn('ip', $morososIps->all())
            ->limit(self::MAX_DETAILS_PER_FINDING)
            ->get(['id', 'full_name', 'ip'])
            ->map(fn (Client $c) => [
                'client_id' => $c->id,
                'full_name' => $c->full_name,
                'ip'        => $c->ip,
            ])
            ->all();

        // IPs bloqueadas cuyo cliente está con servicio vigente → cliente
        // pagando sin servicio. También se reportan IPs sin cliente conocido.
        $activeClientsByIp = Client::query()
            ->whereIn('ip', $morososIps->all())
            ->whereNotIn(DB::raw('UPPER(service_status)'), self::CUT_STATUSES)
            ->get(['id', 'full_name', 'ip', 'service_status'])
            ->keyBy('ip');

        $inListNotSuspended = $activeClientsByIp
            ->take(self::MAX_DETAILS_PER_FINDING)
            ->map(fn (Client $c) => [
                'client_id'      => $c->id,
                'full_name'      => $c->full_name,
                'ip'             => $c->ip,
                'service_status' => $c->service_status,
            ])
            ->values()
            ->all();

        return [
            'suspended_not_in_morosos' => $suspendedNotInList,
            'in_morosos_not_suspended' => $inListNotSuspended,
        ];
    }

    /**
     * Deja el resumen de inconsistencias en la auditoría para que el hallazgo
     * quede trazado aunque la notificación se pierda.
     */
    private function auditFindings(array $report): void
    {
        try {
            Audit::create([
                'table_name' => 'system',
                'operation'  => 'BILLING_INTEGRITY_OP',
                'record_id'  => '0',
                'old_values' => null,
                'new_values' => [
                    'checked_at'     => $report['checked_at'],
                    'total_findings' => $report['total_findings'],
                    'counts'         => $this->findingCounts($report['findings']),
                    'findings'       => $report['findings'],
                    'executor'       => 'system_auto',
                ],
                'user_id'    => null,
                'ip_address' => '127.0.0.1',
            ]);
        } catch (\Throwable $e) {
            Log::error('BillingIntegrityService: fallo al auditar hallazgos: ' . $e->getMessage());
        }
    }

    private function findingCounts(array $findings): array
    {
        return [
            'active_with_open_cut'          => count($findings['active_with_open_cut']),
            'cut_without_open_window'       => count($findings['cut_without_open_window']),
            'invoices_issued_during_cut'    => count($findings['invoices_issued_during_cut']),
            'cut_clients_with_active_plans' => count($findings['cut_clients_with_active_plans']),
            'mikrotik_suspended_not_in_morosos' => count($findings['mikrotik']['suspended_not_in_morosos'] ?? []),
            'mikrotik_in_morosos_not_suspended' => count($findings['mikrotik']['in_morosos_not_suspended'] ?? []),
            'mikrotik_skipped'              => $findings['mikrotik']['skipped'] ?? null,
        ];
    }
}
