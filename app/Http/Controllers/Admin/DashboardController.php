<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics.
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
        // Default to current year
        $year = $request->input('year', date('Y'));
        
        // Get monthly data for the year
        // We want 3 series:
        // 1. Total Invoiced (total_amount of all invoices issued that month)
        // 2. Total Collected (total_amount of paid invoices paid that month - or issued that month and paid)
        //    Let's go with "issued that month and paid" to keep it simple with issue_date, 
        //    OR "paid_at" month. Usually financial charts track cash flow (paid_at) vs sales (issue_date).
        //    Let's stick to sales/invoiced by issue_date for now.
        // 3. Pending Debt (total_amount of pending/failed invoices issued that month)

        $data = Invoice::selectRaw(
                'MONTH(issue_date) as month,
                 SUM(total_amount) as total_invoiced,
                 SUM(CASE WHEN status = "paid" THEN total_amount ELSE 0 END) as total_collected,
                 SUM(CASE WHEN status IN ("pending", "failed") THEN total_amount ELSE 0 END) as total_pending'
            )
            ->whereYear('issue_date', $year)
            ->whereNull('deleted_at') // If using SoftDeletes
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
