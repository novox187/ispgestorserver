<?php
// app/Http/Controllers/MikroTikController.php

namespace App\Http\Controllers;

use App\Services\MikroTikService;
use App\Services\MikroTikQueueSyncService;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;

class MikroTikController extends Controller
{
    public function __construct(protected MikroTikService $mikrotik, protected MikroTikQueueSyncService $sync) {}

    public function systemInfo(): JsonResponse
    {
        try {
            $info = $this->mikrotik->getSystemInfo();
            return response()->json([
                'success' => true,
                'data' => $info[0] ?? [],
            ]);
        } catch (\Exception $e) {
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error obteniendo información del sistema',
                    'error' => $e->getMessage(),
                ],
                500,
            );
        }
    }

    public function wirelessClients(): JsonResponse
    {
        try {
            $clients = $this->mikrotik->getWirelessClients();
            return response()->json([
                'success' => true,
                'data' => $clients,
            ]);
        } catch (\Exception $e) {
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Error obteniendo clientes wireless',
                    'error' => $e->getMessage(),
                ],
                500,
            );
        }
    }

public function queueList(): JsonResponse
{
    try {
        $queues = $this->mikrotik->getQueueList();
        
        return response()->json([
            'success' => true,
            'count' => count($queues),
            'data' => $queues
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error obteniendo la lista de queues con tráfico',
            'error' => $e->getMessage()
        ], 500);
    }
    }

public function getclientbyip(Request $request): JsonResponse
{
    try {
        // Obtener el usuario autenticado
        $user = Auth::user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no autenticado'
            ], 401);
        }

        // Obtener la IP almacenada en la base de datos
        $clientIp = $user->ip;

        if (!$clientIp) {
            return response()->json([
                'success' => false,
                'message' => 'No hay IP asignada a tu usuario en la base de datos'
            ], 400);
        }

        // Buscar el dispositivo en MikroTik
        $deviceInfo = $this->mikrotik->getWifiDeviceByIp($clientIp);

        if (isset($deviceInfo['error']) && $deviceInfo['error']) {
            return response()->json([
                'success' => false,
                'message' => $deviceInfo['message'],
                'user_ip' => $clientIp,
                'error_type' => 'mikrotik_connection'
            ], 503); // Service Unavailable
        }

        if (!$deviceInfo['found']) {
            return response()->json([
                'success' => false,
                'message' => $deviceInfo['message'],
                'user_ip' => $clientIp,
                'data' => $deviceInfo
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Dispositivo WiFi encontrado',
            'user_ip' => $clientIp,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email
            ],
            'data' => $deviceInfo
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error buscando dispositivo WiFi',
            'error' => $e->getMessage()
        ], 500);
    }
}

public function checkIp(Request $request): JsonResponse
{
    try {
        $ip = $request->query('ip');
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return response()->json([
                'success' => false,
                'message' => 'IP inválida',
                'errors' => ['ip' => ['Formato de IP incorrecto']]
            ], 422);
        }

        $inDb = Client::query()->where('ip', $ip)->exists();
        $routerResult = $this->mikrotik->getWifiDeviceByIp($ip);
        $inRouter = isset($routerResult['found']) && $routerResult['found'] === true;

        $status = 'available';
        if ($inDb && $inRouter) $status = 'in_use_both';
        else if ($inDb) $status = 'in_use_db';
        else if ($inRouter) $status = 'in_use_router';

        return response()->json([
            'success' => true,
            'data' => [
                'ip' => $ip,
                'in_db' => $inDb,
                'in_router' => $inRouter,
                'status' => $status
            ],
            'message' => $status === 'available' ? 'IP disponible' : 'IP en uso'
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error verificando IP',
            'error' => $e->getMessage()
        ], 500);
    }
    }


public function syncQueuesCleanup(Request $request): JsonResponse
{
    $user = $request->user();
    if (!$user) {
        return response()->json([
            'success' => false,
            'status' => 'unauthenticated',
            'message' => 'Usuario no autenticado',
        ], 401);
    }
    if (!$user instanceof Employee) {
        return response()->json([
            'success' => false,
            'status' => 'forbidden',
            'message' => 'Acceso restringido a empleados',
        ], 403);
    }
    $async = $request->boolean('async', false);
    if ($async) {
        $userId = $user->id;
        $ip = $request->ip();
        Bus::dispatch(function () use ($userId, $ip) {
            $mikrotik = app(MikroTikService::class);
            $sync = app(MikroTikQueueSyncService::class);
            $sys = $mikrotik->getSystemInfo();
            if (empty($sys)) {
                throw new \RuntimeException('No hay conexión con MikroTik. Revisa MIKROTIK_HOST/USER/PASS y permisos.');
            }
            $writeCheck = $mikrotik->testSimpleQueueWrite();
            if (!$writeCheck['success']) {
                throw new \RuntimeException('La cuenta no puede escribir en /queue/simple. Habilita API y permisos write.');
            }
            $result = $sync->syncQueues(true);
            $summary = [
                'plans_count' => count($result['plans'] ?? []),
                'clients_count' => count($result['clients'] ?? []),
                'cleanup_deleted' => $result['cleanup']['deleted_count'] ?? 0,
            ];
            Audit::create([
                'table_name' => 'mikrotik_queue_sync',
                'operation' => 'SYNC',
                'record_id' => 'cleanup',
                'old_values' => null,
                'new_values' => $summary,
                'user_id' => $userId,
                'ip_address' => $ip,
            ]);
        });
        return response()->json([
            'success' => true,
            'status' => 'queued',
            'message' => 'Sincronización encolada',
            'data' => [
                'async' => true,
            ],
        ], 202);
    }
    try {
        $sys = $this->mikrotik->getSystemInfo();
        if (empty($sys)) {
            return response()->json([
                'success' => false,
                'status' => 'unavailable',
                'message' => 'No hay conexión con MikroTik. Revisa MIKROTIK_HOST/USER/PASS y permisos.',
            ], 503);
        }
        $writeCheck = $this->mikrotik->testSimpleQueueWrite();
        if (!$writeCheck['success']) {
            return response()->json([
                'success' => false,
                'status' => 'forbidden',
                'message' => 'La cuenta no puede escribir en /queue/simple. Habilita API y permisos write.',
                'diagnostic' => $writeCheck,
            ], 403);
        }
        $result = $this->sync->syncQueues(true);
        $summary = [
            'plans_count' => count($result['plans'] ?? []),
            'clients_count' => count($result['clients'] ?? []),
            'cleanup_deleted' => $result['cleanup']['deleted_count'] ?? 0,
        ];
        Audit::create([
            'table_name' => 'mikrotik_queue_sync',
            'operation' => 'SYNC',
            'record_id' => 'cleanup',
            'old_values' => null,
            'new_values' => $summary,
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
        ]);
        return response()->json([
            'success' => true,
            'status' => 'completed',
            'message' => 'Sincronización completada con limpieza',
            'summary' => $summary,
            'data' => $result,
        ]);
    } catch (\Exception $e) {
        Log::error('Error sincronizando queues con limpieza', [
            'error' => $e->getMessage(),
            'user_id' => $user->id,
        ]);
        return response()->json([
            'success' => false,
            'status' => 'failed',
            'message' => 'Error sincronizando queues con limpieza',
            'error' => $e->getMessage(),
        ], 500);
    }
}

/* PLAN CLIENTE */
public function getClientPlans(): JsonResponse
{
    try {
        $user = Auth::user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no autenticado'
            ], 401);
        }

        // Obtener la IP del cliente desde la base de datos
        $clientIp = $user->ip;

        if (!$clientIp) {
            return response()->json([
                'success' => false,
                'message' => 'No hay IP asignada a tu usuario en la base de datos'
            ], 400);
        }

        // Obtener las queues del cliente
        $queuesInfo = $this->mikrotik->getClientQueues($clientIp);

        if (isset($queuesInfo['error']) && $queuesInfo['error']) {
            return response()->json([
                'success' => false,
                'message' => $queuesInfo['message'],
                'client_ip' => $clientIp,
                'error_type' => 'mikrotik_connection'
            ], 503); // Service Unavailable
        }

        if (!$queuesInfo['found']) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontraron planes de ancho de banda para tu IP',
                'client_ip' => $clientIp,
                'data' => $queuesInfo
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Planes de ancho de banda encontrados',
            'client_ip' => $clientIp,
            'user' => [
                'id' => $user->id,
                'name' => $user->name
            ],
            'data' => $queuesInfo
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error obteniendo planes de ancho de banda',
            'error' => $e->getMessage()
        ], 500);
    }
}
}
