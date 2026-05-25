<?php

namespace App\Jobs;

use App\Jobs\Concerns\NotifiesWorkerSummary;
use App\Services\MikroTikQueueSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncMikroTikQueues implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, NotifiesWorkerSummary;

    public int $tries   = 2;
    public int $backoff = 120;
    public int $timeout = 300;

    public function handle(MikroTikQueueSyncService $sync): void
    {
        Log::info('SyncMikroTikQueues: Iniciando sincronización de colas.');

        $result = $sync->syncQueues();

        $summary = [
            'planes'   => count($result['plans']   ?? []),
            'clientes' => count($result['clients'] ?? []),
            'eliminados' => (int) ($result['cleanup']['deleted_count'] ?? 0),
        ];

        Log::info('SyncMikroTikQueues: Sincronización completada.', $summary);

        $this->notifyWorkerSummary(
            workerName: 'SyncMikroTikQueues',
            result:     $summary,
            objective:  'Sincronizar colas de ancho de banda con RouterOS',
        );
    }

    public function failed(Throwable $exception): void
    {
        $this->notifyWorkerFailure(
            workerName: 'SyncMikroTikQueues',
            exception:  $exception,
            objective:  'Sincronizar colas de ancho de banda con RouterOS',
        );
    }
}
