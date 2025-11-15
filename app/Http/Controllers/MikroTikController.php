<?php
// app/Http/Controllers/MikroTikController.php

namespace App\Http\Controllers;

use App\Services\MikroTikService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class MikroTikController extends Controller
{
    public function __construct(protected MikroTikService $mikrotik) {}

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
