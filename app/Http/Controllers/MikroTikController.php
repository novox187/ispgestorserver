<?php
// app/Http/Controllers/MikroTikController.php

namespace App\Http\Controllers;

use App\Services\MikroTikService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
}
