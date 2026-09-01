<?php

namespace App\Http\Controllers\Agent;

use App\Enums\ProvisioningTaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateProvisioningAgent;
use App\Jobs\AdvanceProvisioningSession;
use App\Models\ProvisioningAgent;
use App\Models\ProvisioningTask;
use App\Services\Provisioning\ProvisioningAuditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cola de trabajo que los agentes reclaman por polling saliente.
 *
 * Es la pieza que resuelve el aislamiento del contenedor: la aplicación no
 * puede alcanzar ni la NIC de la oficina ni el WireGuard del hosting, así que
 * en lugar de intentar salir, publica trabajo y espera a que los agentes
 * entren a buscarlo. Nadie abre un puerto y el NAT deja de ser un problema.
 */
class AgentTaskController extends Controller
{
    public function __construct(private readonly ProvisioningAuditor $auditor)
    {
    }

    /**
     * Latido del agente. Además de refrescar `last_seen_at` (que ya hace el
     * middleware), permite al agente republicar sus capabilities: si el
     * administrador cambia la clave del servidor WireGuard, la saga se entera
     * en el siguiente latido y no a mitad de un alta.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $agent = $this->agent($request);

        $validated = $request->validate([
            'agent_version' => ['nullable', 'string', 'max:30'],
            'capabilities'  => ['nullable', 'array'],
            'health'        => ['nullable', 'array'],
        ]);

        $changes = [];
        if (array_key_exists('agent_version', $validated)) {
            $changes['agent_version'] = $validated['agent_version'];
        }
        if (array_key_exists('capabilities', $validated) && is_array($validated['capabilities'])) {
            $changes['capabilities'] = $validated['capabilities'];
        }

        if ($changes !== []) {
            $agent->forceFill($changes)->save();
        }

        return response()->json([
            'data' => [
                'ok'            => true,
                'server_time'   => now()->toIso8601String(),
                'poll_interval' => config('provisioning.agent.poll_interval_seconds'),
                'pending_tasks' => $agent->tasks()
                    ->where('status', ProvisioningTaskStatus::PENDING->value)
                    ->count(),
            ],
        ]);
    }

    /**
     * Entrega hasta N tareas pendientes dirigidas a este agente.
     *
     * El `lockForUpdate` es lo que impide que dos instancias del mismo agente
     * (un reinicio solapado, por ejemplo) se lleven la misma tarea y apliquen
     * dos veces la configuración de un router.
     */
    public function claim(Request $request): JsonResponse
    {
        $agent = $this->agent($request);

        $validated = $request->validate([
            'max' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $limit = min(
            (int) ($validated['max'] ?? 1),
            (int) config('provisioning.agent.max_tasks_per_claim', 1),
        );

        $tasks = DB::transaction(function () use ($agent, $limit) {
            $pending = ProvisioningTask::query()
                ->where('agent_id', $agent->id)
                ->where('status', ProvisioningTaskStatus::PENDING->value)
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            $claimed = [];

            foreach ($pending as $task) {
                // Defensa en profundidad: la tarea ya nace dirigida a un agente
                // concreto, pero se vuelve a comprobar que su rol la admite
                // antes de entregarle nada.
                if (!$agent->canExecute($task->type)) {
                    continue;
                }

                $task->markClaimed();
                $claimed[] = $task;
            }

            return $claimed;
        });

        return response()->json([
            'data' => [
                'tasks'         => array_map(fn (ProvisioningTask $t) => $t->toAgentArray(), $tasks),
                'poll_interval' => config('provisioning.agent.poll_interval_seconds'),
            ],
        ]);
    }

    /**
     * Recoge el resultado de una tarea y despierta a la saga.
     */
    public function report(Request $request, int $id): JsonResponse
    {
        $agent = $this->agent($request);

        $validated = $request->validate([
            'status'        => ['required', 'string', 'in:succeeded,failed'],
            'result'        => ['nullable', 'array'],
            'error_code'    => ['nullable', 'string', 'max:60'],
            'error_message' => ['nullable', 'string', 'max:2000'],
            'logs'          => ['nullable', 'array'],
            'logs.*'        => ['string', 'max:1000'],
        ]);

        $task = ProvisioningTask::query()
            ->where('id', $id)
            ->where('agent_id', $agent->id)
            ->first();

        if ($task === null) {
            return response()->json([
                'error' => ['code' => 'TASK_NOT_FOUND', 'message' => 'La tarea no existe o no es de este agente.'],
            ], 404);
        }

        if ($task->status !== ProvisioningTaskStatus::CLAIMED) {
            // Ya vencida o ya reportada. Se responde 409 para que el agente lo
            // distinga de un error suyo y no reintente en bucle: si la tarea
            // expiró, la saga ya está revirtiendo por su cuenta.
            return response()->json([
                'error' => [
                    'code'    => 'TASK_NOT_CLAIMABLE',
                    'message' => "La tarea está en estado '{$task->status->value}' y ya no admite reporte.",
                ],
            ], 409);
        }

        $result = $validated['result'] ?? [];
        $logs   = $validated['logs'] ?? [];
        if ($logs !== []) {
            $result['logs'] = $logs;
        }

        if ($validated['status'] === 'succeeded') {
            $task->markSucceeded($result);
        } else {
            $task->markFailed(
                $validated['error_code'] ?? 'AGENT_REPORTED_FAILURE',
                $validated['error_message'] ?? 'El agente reportó un fallo sin detalle.',
                $result,
            );
        }

        // La traza del agente se vuelca al canal `provisioning` para que el
        // diagnóstico no dependa de tener acceso al journald de la máquina
        // remota.
        if ($logs !== []) {
            $this->auditor->agentLogs($task->session, $task->type->value, $logs);
        }

        AdvanceProvisioningSession::dispatch($task->session_id);

        return response()->json(['data' => ['ok' => true]]);
    }

    private function agent(Request $request): ProvisioningAgent
    {
        /** @var ProvisioningAgent $agent */
        $agent = $request->attributes->get(AuthenticateProvisioningAgent::REQUEST_ATTRIBUTE);

        return $agent;
    }
}
