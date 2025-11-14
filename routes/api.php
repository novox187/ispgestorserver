<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\AuthClientController;
use App\Http\Controllers\MikroTikController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Clientes con relaciones
/* Route::get('/clientes', [ClienteController::class, 'index']); */
// Auth cliente
Route::post('/client/login', [AuthClientController::class, 'login']);
Route::post('/client/logout', [AuthClientController::class, 'logout'])->middleware('auth:sanctum');
// Ruta protegida que devuelve el cliente autenticado por token desde el controlador
Route::get('/clientes/cliente', [ClienteController::class, 'show'])->middleware('auth:sanctum');

Route::prefix('mikrotik')->group(function () {
    Route::get('/system', [MikroTikController::class, 'systemInfo']);
    Route::get('/wireless-clients', [MikroTikController::class, 'wirelessClients']);
    Route::get('/queue-list', [MikroTikController::class, 'queueList']);
});

// routes/web.php (si necesitas vistas)

Route::get('/mikrotik/dashboard', function () {
    return view('mikrotik.dashboard');
});
