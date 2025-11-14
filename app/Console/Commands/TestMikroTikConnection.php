<?php
// app/Console/Commands/TestMikroTikConnection.php

namespace App\Console\Commands;

use App\Services\MikroTikService;
use Illuminate\Console\Command;

class TestMikroTikConnection extends Command
{
    protected $signature = 'mikrotik:test';
    protected $description = 'Test MikroTik API connection';

    public function handle(MikroTikService $mikrotik): int
    {
        try {
            $this->info('Testing MikroTik connection...');
            
            $info = $mikrotik->getSystemInfo();
            $systemInfo = $info[0] ?? [];
            
            $this->info('✅ Connection successful!');
            $this->line('Device: ' . ($systemInfo['board-name'] ?? 'Unknown'));
            $this->line('Version: ' . ($systemInfo['version'] ?? 'Unknown'));
            $this->line('Uptime: ' . ($systemInfo['uptime'] ?? 'Unknown'));
            
            return self::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('❌ Connection failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}