<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\AuthClientController;
use App\Http\Controllers\MikroTikController;
use App\Http\Controllers\ClientPlanController;
use App\Http\Controllers\walletController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\AuthEmployeeController;
use App\Http\Controllers\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Admin\EmployeeController as AdminEmployeeController;
use App\Http\Controllers\Admin\RoleController as AdminRoleController;
use App\Http\Controllers\Admin\PermissionController as AdminPermissionController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\InvoiceController as AdminInvoiceController;
use App\Http\Controllers\Admin\TransactionController as AdminTransactionController;
use App\Http\Controllers\Admin\ChatController as AdminChatController;
use App\Http\Controllers\Admin\InternetServiceProviderController;
use App\Http\Controllers\Admin\IspConnectionController;
use App\Http\Controllers\Admin\MikrotikRouterController;
use App\Http\Controllers\FirewallController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\Admin\SettingController as AdminSettingController;

// ── Broadcasting Auth (Reverb / Pusher) ──────────────────────────────────────
// Acepta tokens de cliente Y de empleado a través de auth:sanctum
Route::post('/broadcasting/auth', function (Request $request) {
    return Broadcast::auth($request);
})->middleware('auth:sanctum');

// ── Perfil del empleado autenticado ─────────────────────────────────────────
Route::get('/user', [AdminEmployeeController::class, 'profile'])->middleware('auth:sanctum');

// ── Rutas de administración ──────────────────────────────────────────────────
Route::prefix('admin')->middleware('auth:sanctum')->group(function () {

    // Dashboard
    Route::get('/dashboard/full-stats', [DashboardController::class, 'fullStats']);
    Route::get('/dashboard/top-debtors', [DashboardController::class, 'topDebtors']);
    Route::get('/dashboard/chart', [DashboardController::class, 'chart']);

    // Clientes
    Route::get('/clientes/summary', [ClientController::class, 'listSummary'])->middleware('permission:clientes.ver');
    Route::get('/clientes/full/{id}', [ClientController::class, 'showFull'])->middleware('permission:clientes.ver');
    Route::post('/clientes/{id}/suspend', [ClientController::class, 'suspend'])->middleware('permission:clientes.editar');
    Route::post('/clientes/{id}/activate', [ClientController::class, 'activate'])->middleware('permission:clientes.editar');
    Route::post('/clientes/{id}/cancel', [ClientController::class, 'cancel'])->middleware('permission:clientes.editar');
    Route::put('/clientes/{id}', [ClientController::class, 'update'])->middleware('permission:clientes.editar');
    Route::post('/clientes/crear', [ClienteController::class, 'store'])->middleware('permission:clientes.crear');

    // Empleados
    Route::get('/employees', [AdminEmployeeController::class, 'index'])->middleware('permission:usuarios.ver');
    Route::post('/employees', [AdminEmployeeController::class, 'store'])->middleware('permission:usuarios.crear');
    Route::get('/employees/show/{id}', [AdminEmployeeController::class, 'show'])->middleware('permission:usuarios.ver');
    Route::put('/employees/{id}', [AdminEmployeeController::class, 'update'])->middleware('permission:usuarios.editar');
    Route::delete('/employees/{id}', [AdminEmployeeController::class, 'destroy'])->middleware('permission:usuarios.eliminar');
    Route::post('/employees/{id}/restore', [AdminEmployeeController::class, 'restore'])->middleware('permission:usuarios.eliminar');
    Route::delete('/employees/{id}/force', [AdminEmployeeController::class, 'forceDelete'])->middleware('super_admin');
    Route::patch('/employees/{id}/toggle-status', [AdminEmployeeController::class, 'toggleStatus'])->middleware('permission:usuarios.editar');

    // Roles — solo super_admin o acceso_total gestiona roles y permisos
    Route::get('/roles', [AdminRoleController::class, 'index']);
    Route::post('/roles', [AdminRoleController::class, 'store'])->middleware('super_admin');
    Route::get('/roles/permissions', [AdminRoleController::class, 'permissions']);
    Route::get('/roles/{id}', [AdminRoleController::class, 'show']);
    Route::put('/roles/{id}', [AdminRoleController::class, 'update'])->middleware('super_admin');
    Route::delete('/roles/{id}', [AdminRoleController::class, 'destroy'])->middleware('super_admin');
    Route::post('/roles/{id}/permissions', [AdminRoleController::class, 'syncPermissions'])->middleware('super_admin');

    // Permisos CRUD — solo super_admin
    Route::get('/permissions', [AdminPermissionController::class, 'index']);
    Route::post('/permissions', [AdminPermissionController::class, 'store'])->middleware('super_admin');
    Route::put('/permissions/{id}', [AdminPermissionController::class, 'update'])->middleware('super_admin');
    Route::delete('/permissions/{id}', [AdminPermissionController::class, 'destroy'])->middleware('super_admin');

    // Planes
    Route::get('/planes/summary', [AdminPlanController::class, 'listSummary'])->middleware('permission:planes.ver');
    Route::get('/plans', [AdminPlanController::class, 'index'])->middleware('permission:planes.ver');
    Route::post('/planes', [AdminPlanController::class, 'store'])->middleware('permission:planes.crear');
    Route::put('/planes/{id}', [AdminPlanController::class, 'update'])->middleware('permission:planes.editar');
    Route::put('/planes/{id}/status', [AdminPlanController::class, 'setStatus'])->middleware('permission:planes.editar');

    // Routers MikroTik
    Route::get('/mikrotik-routers', [MikrotikRouterController::class, 'index']);
    Route::get('/mikrotik-routers/{id}', [MikrotikRouterController::class, 'show']);
    Route::post('/mikrotik-routers', [MikrotikRouterController::class, 'store'])->middleware('super_admin');
    Route::put('/mikrotik-routers/{id}', [MikrotikRouterController::class, 'update'])->middleware('super_admin');
    Route::delete('/mikrotik-routers/{id}', [MikrotikRouterController::class, 'destroy'])->middleware('super_admin');

    // ISPs
    Route::get('/isps', [InternetServiceProviderController::class, 'index']);
    Route::get('/isps/{id}', [InternetServiceProviderController::class, 'show']);
    Route::post('/isps', [InternetServiceProviderController::class, 'store'])->middleware('super_admin');
    Route::put('/isps/{id}', [InternetServiceProviderController::class, 'update'])->middleware('super_admin');
    Route::delete('/isps/{id}', [InternetServiceProviderController::class, 'destroy'])->middleware('super_admin');

    // Conexiones ISP
    Route::get('/isp-connections', [IspConnectionController::class, 'index']);
    Route::get('/isp-connections/{id}', [IspConnectionController::class, 'show']);
    Route::post('/isp-connections', [IspConnectionController::class, 'store'])->middleware('super_admin');
    Route::put('/isp-connections/{id}', [IspConnectionController::class, 'update'])->middleware('super_admin');
    Route::delete('/isp-connections/{id}', [IspConnectionController::class, 'destroy'])->middleware('super_admin');
    Route::get('/isps/{ispId}/connections', [IspConnectionController::class, 'indexByIsp']);
    Route::post('/isps/{ispId}/connections', [IspConnectionController::class, 'storeForIsp'])->middleware('super_admin');

    // Facturas Admin
    Route::get('/invoices/config-check', [AdminInvoiceController::class, 'configCheck'])->middleware('permission:facturas.ver');
    Route::post('/invoices/generate-auto', [AdminInvoiceController::class, 'generateAuto'])->middleware('permission:facturas.crear');
    Route::post('/invoices/generate-by-contract', [AdminInvoiceController::class, 'generateByContract'])->middleware('permission:facturas.crear');
    Route::post('/invoices/{invoice}/charge', [AdminInvoiceController::class, 'charge'])->middleware('permission:facturas.editar');
    Route::get('/invoices', [AdminInvoiceController::class, 'index'])->middleware('permission:facturas.ver');
    Route::get('/invoices/{invoice}', [AdminInvoiceController::class, 'show'])->middleware('permission:facturas.ver');
    Route::post('/invoices', [AdminInvoiceController::class, 'store'])->middleware('permission:facturas.crear');
    Route::put('/invoices/{invoice}', [AdminInvoiceController::class, 'update'])->middleware('permission:facturas.editar');
    Route::patch('/invoices/{invoice}', [AdminInvoiceController::class, 'update'])->middleware('permission:facturas.editar');
    Route::delete('/invoices/{invoice}', [AdminInvoiceController::class, 'destroy'])->middleware('permission:facturas.eliminar');

    // Configuraciones del sistema (solo admin)
    Route::get('/settings', [AdminSettingController::class, 'index'])->middleware('permission:configuracion.ver');
    Route::post('/settings', [AdminSettingController::class, 'store'])->middleware('permission:configuracion.gestionar');
    Route::put('/settings/{setting}', [AdminSettingController::class, 'update'])->middleware('permission:configuracion.gestionar');
    Route::delete('/settings/{setting}', [AdminSettingController::class, 'destroy'])->middleware('permission:configuracion.gestionar');
    Route::put('/settings', [AdminSettingController::class, 'bulkUpdate'])->middleware('permission:configuracion.gestionar');

    // Importaciones
    Route::get('/import/template/{table}', [App\Http\Controllers\Admin\ImportController::class, 'downloadTemplate']);
    Route::post('/import/validate', [App\Http\Controllers\Admin\ImportController::class, 'validateImport']);
    Route::post('/import/process', [App\Http\Controllers\Admin\ImportController::class, 'processImport']);
    Route::post('/import/history', [App\Http\Controllers\Admin\ImportController::class, 'history']);
    Route::post('/import/rollback/{id}', [App\Http\Controllers\Admin\ImportController::class, 'rollback']);

    // Billetera/Transacciones Admin
    Route::post('/clientes/{id}/add-funds', [AdminTransactionController::class, 'addFunds']);

    // ── Chat Admin ───────────────────────────────────────────────────────────
    Route::prefix('chat')->middleware('permission:soporte.ver')->group(function () {
        Route::get('/conversations', [AdminChatController::class, 'conversations']);
        Route::get('/client/{clientId}/events', [AdminChatController::class, 'clientEvents']);
        Route::get('/{ticketId}/messages', [AdminChatController::class, 'messages']);
        Route::post('/{ticketId}/messages', [AdminChatController::class, 'store'])->middleware('permission:soporte.gestionar');
        Route::put('/{ticketId}/assign', [AdminChatController::class, 'assign'])->middleware('permission:soporte.gestionar');
        Route::put('/{ticketId}/status', [AdminChatController::class, 'updateStatus'])->middleware('permission:soporte.gestionar');
    });
});

// ── Auth Cliente ─────────────────────────────────────────────────────────────
Route::post('/client/login', [AuthClientController::class, 'login']);
Route::post('/client/logout', [AuthClientController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/clientes/cliente', [ClienteController::class, 'show'])->middleware('auth:sanctum');

// ── Auth Empleado ─────────────────────────────────────────────────────────────
Route::post('/employee/login', [AuthEmployeeController::class, 'login']);
Route::post('/employee/logout', [AuthEmployeeController::class, 'logout'])->middleware('auth:sanctum');

// ── MikroTik ─────────────────────────────────────────────────────────────────
Route::prefix('mikrotik')->group(function () {
    Route::get('/system', [MikroTikController::class, 'systemInfo']);
    Route::get('/wireless-clients', [MikroTikController::class, 'wirelessClients']);
    Route::get('/queue-list', [MikroTikController::class, 'queueList']);
    Route::get('/client-wireless-data', [MikroTikController::class, 'getclientbyip'])->middleware('auth:sanctum');
    Route::get('/client-plans', [MikroTikController::class, 'getClientPlans'])->middleware('auth:sanctum');
    Route::get('/ip/check', [MikroTikController::class, 'checkIp']);
    Route::post('/sync/queues/cleanup', [MikroTikController::class, 'syncQueuesCleanup'])->middleware(['auth:sanctum', 'throttle:2,1']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/firewall/snapshot', [FirewallController::class, 'snapshot']);
        Route::post('/firewall/apply', [FirewallController::class, 'apply']);
        Route::post('/firewall/validate', [FirewallController::class, 'validate']);
        Route::get('/firewall/apply-logs', [FirewallController::class, 'applyLogs']);
        Route::post('/firewall/apply-logs/{id}/rollback', [FirewallController::class, 'rollback']);
        Route::get('/firewall/router-status', [FirewallController::class, 'routerStatus']);
        Route::post('/firewall/sync/from-router', [FirewallController::class, 'syncFromRouter']);
        Route::post('/firewall/sync/merge-from-router', [FirewallController::class, 'mergeFromRouter']);
    });
});

// ── Planes ────────────────────────────────────────────────────────────────────
Route::prefix('plans')->group(function () {
    Route::get('/', [ClientPlanController::class, 'getAllPlans']);
    Route::get('/names', [ClientPlanController::class, 'getPlanNames']);
    Route::get('/current', [ClientPlanController::class, 'getCurrentClientPlan'])->middleware('auth:sanctum');
    Route::get('/current/invoices', [ClientPlanController::class, 'getCurrentClientPlanForInvoices'])->middleware('auth:sanctum');
});

// ── Transacciones (Cliente) ───────────────────────────────────────────────────
Route::prefix('transactions')->group(function () {
    Route::get('/client', [TransactionController::class, 'index'])->middleware('auth:sanctum');
    Route::post('/create', [TransactionController::class, 'store'])->middleware('auth:sanctum');
    Route::get('/show/{transaction}', [TransactionController::class, 'show'])->middleware('auth:sanctum');
    Route::put('/update/{transaction}', [TransactionController::class, 'update'])->middleware('auth:sanctum');
    Route::delete('/delete/{transaction}', [TransactionController::class, 'destroy'])->middleware('auth:sanctum');
});

// ── Billetera (Cliente) ───────────────────────────────────────────────────────
Route::prefix('wallet')->group(function () {
    Route::get('/balance', [walletController::class, 'getBalance'])->middleware('auth:sanctum');
});

// ── Configuraciones públicas (cliente) ───────────────────────────────────────
Route::get('/settings/public', [SettingController::class, 'public'])->middleware('auth:sanctum');

// ── Facturas (Cliente) ────────────────────────────────────────────────────────
Route::prefix('invoices')->group(function () {
    Route::get('/all', [InvoiceController::class, 'getAllInvoices'])->middleware('auth:sanctum');
    Route::get('/paid', [InvoiceController::class, 'getPaidInvoices'])->middleware('auth:sanctum');
});

// ── Chat Cliente ──────────────────────────────────────────────────────────────
Route::get('messages', [MessageController::class, 'index'])->middleware('auth:sanctum');
Route::post('messages', [MessageController::class, 'store'])->middleware('auth:sanctum');

// Ticket activo del cliente
Route::get('/ticket/active', function (Request $request) {
    $user = $request->user();
    $ticket = \App\Models\Ticket::where('client_id', $user->id)->latest()->first();
    if (!$ticket) return response()->json(null);
    return response()->json([
        'id'     => $ticket->id,
        'status' => $ticket->status,
        'rating' => $ticket->rating,
        'review' => $ticket->review,
    ]);
})->middleware('auth:sanctum');

// Calificar ticket cerrado
Route::post('/ticket/{ticketId}/rate', function (Request $request, int $ticketId) {
    $request->validate([
        'rating' => 'required|integer|min:1|max:5',
        'review' => 'nullable|string|max:1000',
    ]);
    $user = $request->user();
    $ticket = \App\Models\Ticket::where('id', $ticketId)
        ->where('client_id', $user->id)
        ->where('status', 'closed')
        ->firstOrFail();
    $ticket->update(['rating' => $request->rating, 'review' => $request->review]);
    return response()->json(['message' => '¡Gracias por tu calificación!']);
})->middleware('auth:sanctum');

// ── Vista MikroTik ────────────────────────────────────────────────────────────
Route::get('/mikrotik/dashboard', fn () => view('mikrotik.dashboard'));
