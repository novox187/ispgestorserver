<?php

namespace App\Jobs;

use App\Services\Provisioning\DeviceProvisioningOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Empuja una sesión de aprovisionamiento al siguiente paso.
 *
 * Se encola al detectar un equipo y después de cada reporte de un agente. El
 * orquestador es idempotente —si hay una tarea en vuelo no hace nada—, así que
 * despacharlo de más es inofensivo, y eso es justo lo que permite que el flujo
 * avance a base de eventos sin necesidad de un bucle de sondeo.
 *
 * `$tries = 1` a propósito: reintentar el job podría duplicar tareas contra el
 * mismo router. El manejo de fallos es cosa de la saga, que sabe compensar; la
 * cola solo transporta.
 */
class AdvanceProvisioningSession implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public readonly int $sessionId)
    {
        $this->onQueue('provisioning');
    }

    public function handle(DeviceProvisioningOrchestrator $orchestrator): void
    {
        $orchestrator->advance($this->sessionId);
    }

    /**
     * Si el propio job muere, la sesión quedaría colgada sin que nada la
     * rescate: el vencimiento de tareas solo mira tareas, y aquí puede no haber
     * ninguna. Se deja constancia para que el monitor lo recoja.
     */
    public function failed(Throwable $e): void
    {
        Log::channel('provisioning')->error(
            "[sesión {$this->sessionId}] El job de avance falló definitivamente.",
            ['error' => $e->getMessage()],
        );
    }
}
