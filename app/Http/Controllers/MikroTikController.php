<?php
// app/Http/Controllers/MikroTikController.php

namespace App\Http\Controllers;

use App\Services\MikroTikService;
use App\Services\MikroTikQueueSyncService;
use App\Models\Plan;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

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

public function syncPlans(): JsonResponse
{
    try {
        $sys = $this->mikrotik->getSystemInfo();
        if (empty($sys)) {
            return response()->json([
                'success' => false,
                'message' => 'No hay conexión con MikroTik. Revisa MIKROTIK_HOST/USER/PASS y permisos.',
            ], 503);
        }
        $writeCheck = $this->mikrotik->testSimpleQueueWrite();
        if (!$writeCheck['success']) {
            return response()->json([
                'success' => false,
                'message' => 'La cuenta no puede escribir en /queue/simple. Habilita API y permisos write.',
                'diagnostic' => $writeCheck,
            ], 403);
        }
        $plans = Plan::query()->where('is_active', true)->get();
        $results = [];
        foreach ($plans as $plan) {
            $results[] = [
                'plan_id' => $plan->id,
                'name' => $plan->name,
                'result' => $this->sync->ensurePlanQueue($plan),
            ];
        }
        return response()->json([
            'success' => true,
            'count' => count($results),
            'data' => $results,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error sincronizando planes',
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
