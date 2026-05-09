<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Http\Request;
use App\Services\MikroTikService;
use App\Services\IspCapacityService;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function fullStats(MikroTikService $mikroTikService, IspCapacityService $capacity)
    {
        $capacitySnapshot = $capacity->getCapacitySnapshot();

        // Estadísticas de clientes (queries sobre columnas indexadas)
        $clientStats = [
            'active'    => Client::active()->count(),
            'suspended' => Client::suspended()->count(),
            'limited'   => Client::limited()->count(),
            'total'     => Client::count(),
        ];

        // Resumen de facturas: mes actual + acumulado pendiente
        $now = now();
        $monthlyRow = Invoice::whereYear('issue_date', $now->year)
            ->whereMonth('issue_date', $now->month)
            ->whereNull('deleted_at')
            ->selectRaw(
                'SUM(total_amount) as invoiced_this_month,
                 SUM(CASE WHEN status = "paid" THEN total_amount ELSE 0 END) as paid_this_month'
            )
            ->first();

        $invoiceSummary = [
            'pending_count'        => Invoice::pending()->count(),
            'pending_amount'       => (float) Invoice::pending()->sum('total_amount'),
            'overdue_count'        => Invoice::overdue()->count(),
            'invoiced_this_month'  => (float) ($monthlyRow->invoiced_this_month ?? 0),
            'paid_this_month'      => (float) ($monthlyRow->paid_this_month ?? 0),
        ];

        $mikrotikData = [
            'online' => false,
            'cpu_load' => '0%',
            'uptime' => 'Offline',
            'active_clients' => 0,
            'total_clients' => 150,
            'error' => null
        ];

        if (!$mikroTikService->getClient()) {
            $mikrotikData['error'] = [
                'message' => 'Cliente MikroTik no inicializado',
                'detail' => config('app.debug') ? 'El servicio no tiene un cliente RouterOS configurado/inyectado.' : null,
            ];

            return response()->json([
                'mikrotik'         => $mikrotikData,
                'capacity'         => $capacitySnapshot,
                'clients'          => $clientStats,
                'invoices_summary' => $invoiceSummary,
            ]);
        }

        try {
            $systemInfo = $mikroTikService->getSystemInfo();
            
            if (!empty($systemInfo) && isset($systemInfo[0])) {
                $info = $systemInfo[0];
                $mikrotikData['online'] = true;
                $mikrotikData['cpu_load'] = ($info['cpu-load'] ?? '0') . '%';
                $mikrotikData['uptime'] = $info['uptime'] ?? '0m';
                
                $wirelessClients = $mikroTikService->getWirelessClients();
                $mikrotikData['active_clients'] = is_array($wirelessClients) ? count($wirelessClients) : 0;
                
                $mikrotikData['total_clients'] = $mikroTikService->countActiveQueues();
            } else {
                $mikrotikData['error'] = [
                    'message' => 'Sin respuesta de MikroTik',
                    'detail' => config('app.debug') ? 'getSystemInfo devolvió una respuesta vacía o inesperada.' : null,
                ];
            }
        } catch (\Throwable $e) {
            $mikrotikData['error'] = [
                'message' => 'No se pudo conectar a MikroTik',
                'detail' => config('app.debug') ? $e->getMessage() : null,
                'exception' => config('app.debug') ? get_class($e) : null,
                'code' => config('app.debug') ? $e->getCode() : null,
            ];

            Log::error('Dashboard FullStats MikroTik Error', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        return response()->json([
            'mikrotik'         => $mikrotikData,
            'capacity'         => $capacitySnapshot,
            'clients'          => $clientStats,
            'invoices_summary' => $invoiceSummary,
        ]);
    }

    /**
     * Get dashboard statistics (Legacy - Deprecated).
     */
    public function stats()
    {
        $activeUsers = Client::active()->count();
        
        // Users with debt: Users who have at least one invoice pending or failed
        // Or we can check if balance is negative if that's how it's used, but usually debt implies unpaid invoices.
        // Based on Invoice model, we have scopePendingOrFailed.
        $usersWithDebt = Client::whereHas('invoices', function($query) {
            $query->pendingOrFailed();
        })->count();

        $inactiveUsers = Client::whereIn('service_status', [
            'INACTIVE', 'INACTIVO', 
            'SUSPENDED', 'SUSPENDIDO', 
            'CANCELLED', 'CANCELADO'
        ])->count();

        return response()->json([
            'active_users' => $activeUsers,
            'users_with_debt' => $usersWithDebt,
            'inactive_users' => $inactiveUsers,
        ]);
    }

    public function topDebtors()
    {
        $debtors = Client::whereHas('invoices', function($query) {
                $query->whereIn('status', ['pending', 'failed']);
            })
            ->withSum(['invoices as total_debt' => function($query) {
                $query->whereIn('status', ['pending', 'failed']);
            }], 'total_amount')
            ->withCount(['invoices as pending_invoices_count' => function($query) {
                $query->whereIn('status', ['pending', 'failed']);
            }])
            ->orderByDesc('total_debt')
            ->take(5)
            ->get(['id', 'full_name', 'email']);

        return response()->json($debtors);
    }

    public function chart(Request $request)
    {
        // Default to the most recent year that has invoice data, falling back to current year.
        // This prevents the chart from appearing empty when existing invoices belong to a
        // prior year (e.g., the system was set up in 2025 but the current year is 2026).
        $defaultYear = Invoice::selectRaw('YEAR(issue_date) as year')
            ->orderByDesc('year')
            ->value('year') ?? (int) date('Y');

        $year = (int) $request->input('year', $defaultYear);

        $data = Invoice::selectRaw(
                'MONTH(issue_date) as month,
                 SUM(total_amount) as total_invoiced,
                 SUM(CASE WHEN status = "paid" THEN total_amount ELSE 0 END) as total_collected,
                 SUM(CASE WHEN status IN ("pending", "failed") THEN total_amount ELSE 0 END) as total_pending'
            )
            ->whereYear('issue_date', $year)
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // Format for frontend: 12 months, fill zeros if no data
        $months = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        
        $invoiced = array_fill(0, 12, 0);
        $collected = array_fill(0, 12, 0);
        $pending = array_fill(0, 12, 0);

        foreach ($data as $row) {
            $idx = $row->month - 1; // 0-indexed
            if ($idx >= 0 && $idx < 12) {
                $invoiced[$idx] = (float) $row->total_invoiced;
                $collected[$idx] = (float) $row->total_collected;
                $pending[$idx] = (float) $row->total_pending;
            }
        }

        return response()->json([
            'year' => $year,
            'labels' => $months,
            'datasets' => [
                [
                    'label' => 'Facturado',
                    'data' => $invoiced,
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'tension' => 0.4,
                    'fill' => true,
                ],
                [
                    'label' => 'Cobrado',
                    'data' => $collected,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'tension' => 0.4,
                    'fill' => true,
                ],
                [
                    'label' => 'Pendiente',
                    'data' => $pending,
                    'borderColor' => '#f97316',
                    'backgroundColor' => 'rgba(249, 115, 22, 0.1)',
                    'tension' => 0.4,
                    'fill' => true,
                ]
            ]
        ]);
    }
}
