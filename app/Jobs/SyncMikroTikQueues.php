<?php

namespace App\Jobs;

use App\Services\MikroTikQueueSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncMikroTikQueues implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $backoff = 120;
    public int $timeout = 300;

    public function handle(MikroTikQueueSyncService $sync): void
    {
        Log::info('SyncMikroTikQueues: Iniciando sincronización de colas.');

        $result = $sync->syncQueues();

        Log::info('SyncMikroTikQueues: Sincronización completada.', [
            'planes'   => count($result['plans']   ?? []),
            'clientes' => count($result['clients'] ?? []),
        ]);
    }
}
