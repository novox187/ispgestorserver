<?php
// app/Services/MikroTikService.php

namespace App\Services;

use RouterOS\Client;
use RouterOS\Query;
use Exception;
use Illuminate\Support\Facades\Log;

class MikroTikService
{
    protected $client;

    public function __construct(?Client $client)
    {
        $this->client = $client;
    }

    //ver la informacion del router
    public function getSystemInfo(): array
    {
        if (!$this->client) {
            return [];
        }

        try {
            $query = new Query('/system/resource/print');
            return $this->client->query($query)->read();
        } catch (Exception $e) {
            Log::error('MikroTik System Info Error: ' . $e->getMessage());
            return [];
        }
    }

    //ver todos los dispositivos conectados por red wifi
    public function getWirelessClients(): array
    {
        if (!$this->client) {
            return [];
        }

        try {
            $query = new Query('/interface/wireless/registration-table/print');
            return $this->client->query($query)->read();
        } catch (Exception $e) {
            Log::error('MikroTik Wireless Clients Error: ' . $e->getMessage());
            return [];
        }
    }

    // ver los planes creados
    public function getQueueList(): array
    {
        if (!$this->client) {
            return [];
        }

        try {
            $query = new Query('/queue/simple/print');
            $queues = $this->client->query($query)->read();
        
        // Filtrar solo las queues con parent = "none"
            $filteredQueues = array_filter($queues, function($queue) {
                $parent = $queue['parent'] ?? 'none';
                return $parent === 'none';
            });
        
        // Formatear los datos para mejor legibilidad
            $formattedQueues = [];
            foreach ($filteredQueues as $queue) {
                $formattedQueues[] = [
                    'id' => $queue['.id'] ?? '',
                    'name' => $queue['name'] ?? '',
                    'target' => $queue['target'] ?? '',
                    'parent' => $queue['parent'] ?? 'none',
                    'priority' => $queue['priority'] ?? '8',
                    'max_limit' => $queue['max-limit'] ?? '0/0',
                    'limit_at' => $queue['limit-at'] ?? '0/0',
                    'burst_limit' => $queue['burst-limit'] ?? '0/0',
                    'burst_threshold' => $queue['burst-threshold'] ?? '0/0',
                    'burst_time' => $queue['burst-time'] ?? '0s',
                    'packets' => $queue['packets'] ?? '0/0',
                    'bytes' => $queue['bytes'] ?? '0/0',
                    'rate' => $queue['rate'] ?? '0/0',
                    'total_packets' => $queue['total-packets'] ?? '0',
                    'total_bytes' => $queue['total-bytes'] ?? '0',
                    'disabled' => isset($queue['disabled']) ? ($queue['disabled'] === 'true') : false,
                ];
            }
        
            return $formattedQueues;
        } catch (Exception $e) {
            Log::error('MikroTik Queue List Error: ' . $e->getMessage());
            throw $e;
        }
    }



public function getWifiDeviceByIp(string $ipAddress): array
{
    if (!$this->client) {
        return [
            'found' => false,
            'error' => true,
            'message' => 'Cliente MikroTik no configurado correctamente'
        ];
    }

    try {
        // Buscar en la tabla de registro wireless
        $wirelessQuery = new Query('/interface/wireless/registration-table/print');
        $wirelessClients = $this->client->query($wirelessQuery)->read();

        // Buscar por IP en el campo 'last-ip'
        $wirelessDevice = $this->findWirelessClientByLastIp($wirelessClients, $ipAddress);

        if (!empty($wirelessDevice)) {
            return [
                'found' => true,
                'ip_address' => $ipAddress,
                'mac_address' => $wirelessDevice['mac-address'] ?? '',
                'interface' => $wirelessDevice['interface'] ?? '',
                'signal_strength' => $wirelessDevice['signal-strength'] ?? '',
                'signal_quality' => $this->calculateSignalQuality($wirelessDevice['signal-strength'] ?? ''),
                'tx_rate' => $wirelessDevice['tx-rate'] ?? '',
                'rx_rate' => $wirelessDevice['rx-rate'] ?? '',
                'uptime' => $wirelessDevice['uptime'] ?? '',
                'ssid' => $wirelessDevice['ssid'] ?? '',
                'packets' => $wirelessDevice['packets'] ?? '',
                'bytes' => $wirelessDevice['bytes'] ?? '',
                'frames' => $wirelessDevice['frames'] ?? '',
                'last_activity' => $wirelessDevice['last-activity'] ?? '',
                'signal_to_noise' => $wirelessDevice['signal-to-noise'] ?? '',
                'distance' => $wirelessDevice['distance'] ?? '',
                'authentication_type' => $wirelessDevice['authentication-type'] ?? '',
                'encryption' => $wirelessDevice['encryption'] ?? '',
                'status' => 'connected'
            ];
        }

        return [
            'found' => false,
            'message' => 'Dispositivo no encontrado en la tabla wireless'
        ];

    } catch (Exception $e) {
        Log::error('MikroTik WiFi Device by IP Error: ' . $e->getMessage());
        return [
            'found' => false,
            'error' => true,
            'message' => 'Error de conexión con el router MikroTik: ' . $e->getMessage()
        ];
    }
}

private function findWirelessClientByLastIp(array $wirelessClients, string $ipAddress): array
{
    foreach ($wirelessClients as $client) {
        // Buscar en el campo 'last-ip'
        if (isset($client['last-ip']) && $client['last-ip'] === $ipAddress) {
            return $client;
        }
    }
    
    return [];
}

private function calculateSignalQuality(string $signalStrength): string
{
    // Extraer el valor numérico del formato "-36@24Mbps"
    if (preg_match('/-(\d+)/', $signalStrength, $matches)) {
        $signal = (int) $matches[1];
        
        if ($signal <= 30) return 'Excelente';
        if ($signal <= 50) return 'Bueno';
        if ($signal <= 70) return 'Regular';
        return 'Débil';
    }
    
    return 'Desconocido';
}



/* PLAN CLIENTE */

public function getClientQueues(string $clientIp): array
{
    if (!$this->client) {
        return [
            'found' => false,
            'error' => true,
            'message' => 'Cliente MikroTik no configurado correctamente'
        ];
    }

    try {
        // Obtener todas las queues
        $query = new Query('/queue/simple/print');
        $queues = $this->client->query($query)->read();

        // Filtrar queues que contengan la IP del cliente
        $clientQueues = array_filter($queues, function($queue) use ($clientIp) {
            $target = $queue['target'] ?? '';
            
            // Buscar la IP en el campo target (puede estar en formato IP, IP/mask, etc.)
            return str_contains($target, $clientIp);
        });

        // Filtrar solo las queues con parent = "none"
        $clientQueues = array_filter($queues, function($queue) {
            $parent = $queue['parent'] ?? 'none';
            return $parent === 'none';
        });

        // Formatear la información de las queues
        $formattedQueues = [];
        foreach ($clientQueues as $queue) {
            $maxLimit = $this->parseBandwidth($queue['max-limit'] ?? '0/0');
            $currentRate = $this->parseBandwidth($queue['rate'] ?? '0/0');
            
            $formattedQueues[] = [
                'id' => $queue['.id'] ?? '',
                'name' => $queue['name'] ?? '',
                
                // Límites de ancho de banda
                'max_limit' => $queue['max-limit'] ?? '0/0',
                'max_limit_upload' => $this->formatBandwidth($maxLimit['upload']),
                'max_limit_download' => $this->formatBandwidth($maxLimit['download']),
                
                // Límite garantizado
                'limit_at' => $queue['limit-at'] ?? '0/0',
                 
                'bytes' => $queue['bytes'] ?? '0/0',
            ];
        }

        return [
            'found' => count($formattedQueues) > 0,
            'count' => count($formattedQueues),
            'queues' => $formattedQueues
        ];

    } catch (Exception $e) {
        Log::error('MikroTik Client Queues Error: ' . $e->getMessage());
        return [
            'found' => false,
            'error' => true,
            'message' => 'Error de conexión con el router MikroTik: ' . $e->getMessage()
        ];
    }
}

private function parseBandwidth(string $bandwidth): array
{
    // Convierte "10M/5M" a [upload, download] en bits/segundo
    $parts = explode('/', $bandwidth);
    
    $upload = $this->convertToBits($parts[0] ?? '0');
    $download = $this->convertToBits($parts[1] ?? '0');
    
    return [
        'upload' => $upload,
        'download' => $download
    ];
}

private function convertToBits(string $value): int
{
    $value = trim($value);
    
    if ($value === '0') return 0;
    
    $multipliers = [
        'G' => 1000000000,
        'M' => 1000000,
        'K' => 1000,
    ];
    
    foreach ($multipliers as $suffix => $multiplier) {
        if (str_ends_with($value, $suffix)) {
            $number = (float) substr($value, 0, -1);
            return (int) ($number * $multiplier);
        }
    }
    
    return (int) $value;
}

private function formatBandwidth(int $bits): string
{
    if ($bits === 0) return '0';
    
    $units = ['', 'K', 'M', 'G'];
    $unitIndex = 0;
    
    while ($bits >= 1000 && $unitIndex < count($units) - 1) {
        $bits /= 1000;
        $unitIndex++;
    }
    
    return round($bits, 2) . $units[$unitIndex];
}

}