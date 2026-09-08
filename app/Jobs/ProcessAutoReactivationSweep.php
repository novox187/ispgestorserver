<?php

namespace App\Jobs;

use App\Jobs\Concerns\NotifiesWorkerSummary;
use App\Models\AutomationSetting;
use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Barrido diario de reactivaciones.
 *
 * Antes vivía suelto en `bootstrap/app.php` como `billing:reactivate`: no se
 * podía desactivar ni reprogramar desde Workers Automáticos, no respetaba el
 * interruptor de la automatización y no dejaba resumen. Aquí se comporta como
 * el resto de workers del ciclo: gobernable desde el panel y con resumen
 * notificado.
 *
 * Despacha un ProcessAutoReactivation por cliente suspendido; ese job es quien
 * decide, cliente a cliente, si la deuda vencida está saldada.
 */
class ProcessAutoReactivationSweep implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, NotifiesWorkerSummary;

    public int $tries   = 3;
    public int $backoff = 120;
    public int $timeout = 120;

    public function handle(): void
    {
        AutomationSetting::flushCache();
        $automation = AutomationSetting::getCached('auto_reactivation');

        if (!$automation) {
            Log::warning('ProcessAutoReactivationSweep: no existe el registro AutomationSetting "auto_reactivation". Job abortado.');
            return;
        }

        if (!$automation->enabled) {
            Log::info('ProcessAutoReactivationSweep: la Reactivación Automática está DESACTIVADA. Ningún cliente será revisado.');
            return;
        }

        $automation->updateQuietly(['last_run_at' => now()]);

        $suspendedClients = Client::whereIn('service_status', ['suspended', 'SUSPENDED', 'SUSPENDIDO'])
            ->with(['wallet'])
            ->get();

        if ($suspendedClients->isEmpty()) {
            Log::info('ProcessAutoReactivationSweep: no hay clientes suspendidos que revisar.');
            return;
        }

        $queue      = config('billing.queue.reactivations');
        $dispatched = 0;

        foreach ($suspendedClients as $client) {
            ProcessAutoReactivation::dispatch($client)->onQueue($queue);
            $dispatched++;
        }

        Log::info("ProcessAutoReactivationSweep: {$dispatched} revisión(es) de reactivación encoladas.");

        $this->notifyWorkerSummary(
            workerName: 'ProcessAutoReactivationSweep',
            result:     [
                'suspendidos_revisados' => $suspendedClients->count(),
                'revisiones_encoladas'  => $dispatched,
            ],
            objective:  'Reactivar clientes suspendidos que ya no tienen deuda vencida',
        );
    }

    public function failed(Throwable $exception): void
    {
        $this->notifyWorkerFailure(
            workerName: 'ProcessAutoReactivationSweep',
            exception:  $exception,
            objective:  'Reactivar clientes suspendidos que ya no tienen deuda vencida',
        );
    }
}
