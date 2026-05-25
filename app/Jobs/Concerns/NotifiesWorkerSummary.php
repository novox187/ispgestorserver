<?php

namespace App\Jobs\Concerns;

use App\Notifications\Core\Facades\Notify;
use App\Notifications\Messages\WorkerCompletedNotification;
use App\Notifications\Messages\WorkerFailedNotification;
use Throwable;

/**
 * Trait reutilizable que jobs automatizados pueden incorporar para emitir un
 * NotificationMessage de resumen al finalizar `handle()` o de fallo en `failed()`.
 *
 * Uso esperado:
 *
 *   class MyJob implements ShouldQueue {
 *       use NotifiesWorkerSummary;
 *
 *       public function handle(): void {
 *           $result = $this->doWork();
 *           $this->notifyWorkerSummary('MyJob', $result, 'Procesar X');
 *       }
 *
 *       public function failed(Throwable $e): void {
 *           $this->notifyWorkerFailure('MyJob', $e, 'Procesar X');
 *       }
 *   }
 */
trait NotifiesWorkerSummary
{
    protected function notifyWorkerSummary(string $workerName, array $result, ?string $objective = null): void
    {
        try {
            Notify::dispatch(WorkerCompletedNotification::build($workerName, $result, $objective));
        } catch (Throwable $e) {
            // El envío de la notificación no debe romper el job principal.
            \Log::warning('notifyWorkerSummary: fallo al despachar resumen.', [
                'worker' => $workerName,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    protected function notifyWorkerFailure(string $workerName, Throwable $exception, ?string $objective = null): void
    {
        try {
            Notify::dispatch(WorkerFailedNotification::build($workerName, $exception, $objective));
        } catch (Throwable $e) {
            \Log::warning('notifyWorkerFailure: fallo al despachar alerta de error.', [
                'worker' => $workerName,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
