<?php
// app/Console/Commands/TestMikroTikConnection.php

namespace App\Console\Commands;

use App\Models\MikrotikRouter;
use App\Services\MikroTikService;
use Illuminate\Console\Command;
use RouterOS\Query;

class TestMikroTikConnection extends Command
{
    protected $signature = 'mikrotik:test {--trace : Muestra el stack trace completo} {--write-test : Prueba permisos de escritura en /queue/simple}';
    protected $description = 'Test MikroTik API connection (usa el router primary configurado en BD)';

    public function handle(MikroTikService $mikrotik): int
    {
        try {
            $router = MikrotikRouter::primaryRouter();
            if (!$router) {
                $this->error('❌ No hay router primary configurado en BD. Cree uno desde el panel: Mikrotik → Dispositivos.');
                return self::FAILURE;
            }

            $this->info('Testing MikroTik connection...');
            $this->line(
                "Target: {$router->host}:{$router->port} "
                . "(router='{$router->name}', user={$router->username}, "
                . 'active=' . ($router->is_active ? 'yes' : 'no') . ')'
            );

            if (!$mikrotik->getClient()) {
                $this->error('❌ Connection failed: Cliente MikroTik no inicializado (revisa credenciales del router primary y alcance de red).');
                return self::FAILURE;
            }

            $info = $mikrotik->runQuery(new Query('/system/resource/print'));
            $systemInfo = $info[0] ?? null;

            if (!$systemInfo || !is_array($systemInfo)) {
                $this->error('❌ Connection failed: No se recibió respuesta válida del router.');
                $this->line('Sugerencias: verifica que la API esté habilitada, el puerto sea accesible (8728/8729), firewall/NAT permita el acceso y las credenciales sean correctas.');
                return self::FAILURE;
            }

            $this->info('✅ Connection successful!');
            $this->line('Device: ' . ($systemInfo['board-name'] ?? 'Unknown'));
            $this->line('Version: ' . ($systemInfo['version'] ?? 'Unknown'));
            $this->line('Uptime: ' . ($systemInfo['uptime'] ?? 'Unknown'));

            if ((bool) $this->option('write-test')) {
                $this->line('');
                $this->info('Testing write permissions (/queue/simple)...');
                $writeTest = $mikrotik->testSimpleQueueWrite();
                $ok = (bool) ($writeTest['success'] ?? false);

                if ($ok) {
                    $this->info('✅ Write test successful!');
                } else {
                    $this->error('❌ Write test failed: ' . (string) ($writeTest['message'] ?? 'Unknown error'));
                }
            }

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error('❌ Connection failed');
            $this->line('Type: ' . $e::class);
            $this->line('Message: ' . $e->getMessage());
            $this->line('Code: ' . (string) $e->getCode());
            $this->line('Location: ' . $e->getFile() . ':' . (string) $e->getLine());

            $previous = $e->getPrevious();
            $depth = 1;
            while ($previous) {
                $this->line('');
                $this->line('Previous #' . $depth . ': ' . $previous::class);
                $this->line('Message: ' . $previous->getMessage());
                $this->line('Code: ' . (string) $previous->getCode());
                $this->line('Location: ' . $previous->getFile() . ':' . (string) $previous->getLine());
                $previous = $previous->getPrevious();
                $depth++;
            }

            if ((bool) $this->option('trace') || $this->output->isVerbose()) {
                $this->line('');
                $this->line($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }
}
