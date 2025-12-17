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

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Clientes con relaciones

// Rutas para la administracion
Route::prefix('admin')->middleware('auth:sanctum')->group(function () {
// Listado resumido y detalle completo de un cliente
Route::get('/clientes/summary', [ClientController::class, 'listSummary']);
Route::get('/clientes/full/{id}', [ClientController::class, 'showFull']);
// Crear cliente (público/admin segun protección que se agregue)
Route::post('/clientes/crear', [ClienteController::class, 'store']);
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
    Route::post('/sync/plans', [MikroTikController::class, 'syncAll'])->middleware('auth:sanctum');
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
