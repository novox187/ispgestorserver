<?php

namespace App\Jobs;

use App\Models\ProvisioningTask;
use App\Services\Provisioning\ProvisioningAuditor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Vence las tareas que ningún agente reportó y despierta a la saga.
 *
 * Sin esto, un agente que muere a mitad de aplicar la VPN dejaría la sesión
 * esperando para siempre —y con ella una interfaz WireGuard a medio crear en un
 * router y una dirección retenida del pool—. Es la red de seguridad que
 * convierte «el agente desapareció» en «la saga revierte y avisa».
 *
 * Cubre los dos casos de vencimiento y ambos importan: una tarea `claimed` que
 * venció es un agente que murió ejecutando; una `pending` que venció es un
 * agente que nunca llegó a recogerla.
 */
class ExpireStaleProvisioningTasks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const SETTING_KEY = 'provisioning_task_watchdog';

    public int $tries = 1;
    public int $timeout = 120;

    public function handle(ProvisioningAuditor $auditor): void
    {
        $expired = ProvisioningTask::query()
            ->overdue()
            ->with('session')
            ->get();

        foreach ($expired as $task) {
            try {
                $wasClaimed = $task->claimed_at !== null;
                $task->markExpired();

                Log::channel(ProvisioningAuditor::LOG_CHANNEL)->warning(
                    "[sesión {$task->session_id}] Tarea {$task->type->value} vencida sin reporte.",
                    [
                        'task_id'     => $task->id,
                        'was_claimed' => $wasClaimed,
                        'agent_id'    => $task->agent_id,
                    ],
                );

                if ($task->session !== null) {
                    $auditor->session($task->session, ProvisioningAuditor::STEP_FAILED, [
                        'task_id'       => $task->id,
                        'task_type'     => $task->type->value,
                        'error_code'    => 'TASK_TIMEOUT',
                        'error_message' => $wasClaimed
                            ? 'El agente reclamó la tarea y no reportó a tiempo.'
                            : 'Ningún agente recogió la tarea dentro del plazo.',
                    ]);
                }

                // La saga decide: si ya se había aplicado algo, compensa.
                AdvanceProvisioningSession::dispatch($task->session_id);
            } catch (Throwable $e) {
                Log::channel(ProvisioningAuditor::LOG_CHANNEL)->error(
                    'ExpireStaleProvisioningTasks: excepción venciendo una tarea.',
                    ['task_id' => $task->id, 'error' => $e->getMessage()],
                );
            }
        }
    }
}
