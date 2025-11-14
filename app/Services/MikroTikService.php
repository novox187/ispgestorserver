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

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    //ver la informacion del router
    public function getSystemInfo(): array
    {
        try {
            $query = new Query('/system/resource/print');
            return $this->client->query($query)->read();
        } catch (Exception $e) {
            Log::error('MikroTik System Info Error: ' . $e->getMessage());
            throw $e;
        }
    }

    //ver todos los dispositivos conectados por red wifi
    public function getWirelessClients(): array
    {
        try {
            $query = new Query('/interface/wireless/registration-table/print');
            return $this->client->query($query)->read();
        } catch (Exception $e) {
            Log::error('MikroTik Wireless Clients Error: ' . $e->getMessage());
            throw $e;
        }
    }

    // ver los planes creados
    public function getQueueList(): array
    {
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
}