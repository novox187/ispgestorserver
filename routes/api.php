<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\AuthClientController;
use App\Http\Controllers\MikroTikController;
use App\Http\Controllers\ClientPlanController;
use App\Http\Controllers\walletController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\AuthEmployeeController;
use App\Http\Controllers\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Admin\EmployeeController as AdminEmployeeController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\InvoiceController as AdminInvoiceController;
use App\Http\Controllers\Admin\TransactionController as AdminTransactionController;
use App\Http\Controllers\Admin\InternetServiceProviderController;
use App\Http\Controllers\Admin\IspConnectionController;

Route::get('/user', [AdminEmployeeController::class, 'profile'])->middleware('auth:sanctum');

// Clientes con relaciones

// Rutas para la administracion
Route::prefix('admin')->middleware('auth:sanctum')->group(function () {
    // Dashboard
    Route::get('/dashboard/full-stats', [DashboardController::class, 'fullStats']);
    Route::get('/dashboard/top-debtors', [DashboardController::class, 'topDebtors']);
    Route::get('/dashboard/chart', [DashboardController::class, 'chart']);
    Route::get('/clientes/summary', [ClientController::class, 'listSummary']);
Route::get('/clientes/full/{id}', [ClientController::class, 'showFull']);
Route::post('/clientes/{id}/suspend', [ClientController::class, 'suspend']);
Route::post('/clientes/{id}/activate', [ClientController::class, 'activate']);
Route::post('/clientes/{id}/cancel', [ClientController::class, 'cancel']);
Route::put('/clientes/{id}', [ClientController::class, 'update']);
// Crear cliente (público/admin segun protección que se agregue)
Route::post('/clientes/crear', [ClienteController::class, 'store']);

// Empleados
Route::get('/roles', [AdminEmployeeController::class, 'getRoles']);
Route::get('/employees', [AdminEmployeeController::class, 'listSummary']);
Route::get('/employees/show/{id}', [AdminEmployeeController::class, 'show']);
Route::post('/employees', [AdminEmployeeController::class, 'store']);
Route::put('/employees/{id}', [AdminEmployeeController::class, 'update']);
Route::delete('/employees/{id}', [AdminEmployeeController::class, 'destroy']);

// Planes con features
Route::get('/planes/summary', [AdminPlanController::class, 'listSummary']);
Route::get('/plans', [AdminPlanController::class, 'index']); // Nueva ruta para listado simple
Route::post('/planes', [AdminPlanController::class, 'store']);
Route::put('/planes/{id}', [AdminPlanController::class, 'update']);
Route::put('/planes/{id}/status', [AdminPlanController::class, 'setStatus']);

// Proveedores de Internet (ISPs)
Route::get('/isps', [InternetServiceProviderController::class, 'index']);
Route::get('/isps/{id}', [InternetServiceProviderController::class, 'show']);
Route::post('/isps', [InternetServiceProviderController::class, 'store'])->middleware('super_admin');
Route::put('/isps/{id}', [InternetServiceProviderController::class, 'update'])->middleware('super_admin');
Route::delete('/isps/{id}', [InternetServiceProviderController::class, 'destroy'])->middleware('super_admin');

// Conexiones/Enlaces de ISPs
Route::get('/isp-connections', [IspConnectionController::class, 'index']);
Route::get('/isp-connections/{id}', [IspConnectionController::class, 'show']);
Route::post('/isp-connections', [IspConnectionController::class, 'store'])->middleware('super_admin');
Route::put('/isp-connections/{id}', [IspConnectionController::class, 'update'])->middleware('super_admin');
Route::delete('/isp-connections/{id}', [IspConnectionController::class, 'destroy'])->middleware('super_admin');
Route::get('/isps/{ispId}/connections', [IspConnectionController::class, 'indexByIsp']);
Route::post('/isps/{ispId}/connections', [IspConnectionController::class, 'storeForIsp'])->middleware('super_admin');

// Facturas Admin
    Route::post('/invoices/generate-auto', [AdminInvoiceController::class, 'generateAuto']);
    Route::apiResource('/invoices', AdminInvoiceController::class);

    // Import Routes
    Route::get('/import/template/{table}', [App\Http\Controllers\Admin\ImportController::class, 'downloadTemplate']);
    Route::post('/import/validate', [App\Http\Controllers\Admin\ImportController::class, 'validateImport']);
    Route::post('/import/process', [App\Http\Controllers\Admin\ImportController::class, 'processImport']);
    Route::post('/import/history', [App\Http\Controllers\Admin\ImportController::class, 'history']);
    Route::post('/import/rollback/{id}', [App\Http\Controllers\Admin\ImportController::class, 'rollback']);
    
    // Transacciones/Billetera Admin
    Route::post('/clientes/{id}/add-funds', [AdminTransactionController::class, 'addFunds']);
});


// Auth cliente
Route::post('/client/login', [AuthClientController::class, 'login']);
Route::post('/client/logout', [AuthClientController::class, 'logout'])->middleware('auth:sanctum');
// Ruta protegida que devuelve el cliente autenticado por token desde el controlador
Route::get('/clientes/cliente', [ClienteController::class, 'show'])->middleware('auth:sanctum');

// Auth empleado (employee)
Route::post('/employee/login', [AuthEmployeeController::class, 'login']);
Route::post('/employee/logout', [AuthEmployeeController::class, 'logout'])->middleware('auth:sanctum');

Route::prefix('mikrotik')->group(function () {
    Route::get('/system', [MikroTikController::class, 'systemInfo']);
    Route::get('/wireless-clients', [MikroTikController::class, 'wirelessClients']);
    Route::get('/queue-list', [MikroTikController::class, 'queueList']);
    Route::get('/client-wireless-data', [MikroTikController::class, 'getclientbyip'])->middleware('auth:sanctum');
    Route::get('/client-plans', [MikroTikController::class, 'getClientPlans'])->middleware('auth:sanctum');
    Route::get('/ip/check', [MikroTikController::class, 'checkIp']);
    Route::post('/sync/queues/cleanup', [MikroTikController::class, 'syncQueuesCleanup'])->middleware(['auth:sanctum', 'throttle:2,1']);
});

// Rutas para planes
Route::prefix('plans')->group(function () {
    // Obtener todos los planes
    Route::get('/', [ClientPlanController::class, 'getAllPlans']);
    Route::get('/names', [ClientPlanController::class, 'getPlanNames']);
    // Obtener plan actual del cliente (requiere autenticación o client_id)
    Route::get('/current', [ClientPlanController::class, 'getCurrentClientPlan'])->middleware('auth:sanctum');
    Route::get('/current/invoices', [ClientPlanController::class, 'getCurrentClientPlanForInvoices'])->middleware('auth:sanctum');
});

Route::prefix('transactions')->group(function () {
    Route::get('/client', [TransactionController::class, 'index'])->middleware('auth:sanctum');
    Route::post('/create', [TransactionController::class, 'store'])->middleware('auth:sanctum');
    Route::get('/show/{transaction}', [TransactionController::class, 'show'])->middleware('auth:sanctum');
    Route::put('/update/{transaction}', [TransactionController::class, 'update'])->middleware('auth:sanctum');
    Route::delete('/delete/{transaction}', [TransactionController::class, 'destroy'])->middleware('auth:sanctum');
});

Route::prefix('wallet')->group(function () {
    Route::get('/balance', [walletController::class, 'getBalance'])->middleware('auth:sanctum');
});

Route::prefix('invoices')->group(function () {
    Route::get('/all', [InvoiceController::class, 'getAllInvoices'])->middleware('auth:sanctum');
    Route::get('/paid', [InvoiceController::class, 'getPaidInvoices'])->middleware('auth:sanctum');
});

Route::get('messages', [MessageController::class, 'index'])->middleware('auth:sanctum');
Route::post('messages', [MessageController::class, 'store'])->middleware('auth:sanctum');

// routes/web.php (si necesitas vistas)

Route::get('/mikrotik/dashboard', function () {
    return view('mikrotik.dashboard');
});
