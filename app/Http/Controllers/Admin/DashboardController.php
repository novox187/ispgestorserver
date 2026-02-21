<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;

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
}
