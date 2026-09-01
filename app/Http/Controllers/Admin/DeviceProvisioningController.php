<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProvisioningStatus;
use App\Http\Controllers\Controller;
use App\Models\Audit;
use App\Models\DeviceProvisioningSession;
use App\Services\Provisioning\DeviceProvisioningOrchestrator;
use App\Services\Provisioning\ProvisioningAuditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Vista y control de las altas de dispositivos desde el panel.
 *
 * En condiciones normales aquí no hay nada que hacer: el flujo es automático de
 * punta a punta. Estos endpoints existen para lo que se sale de lo normal —
 * aprobar cuando la aprobación automática está desactivada, cancelar un alta
 * que no debía ocurrir y forzar la reversión de una que quedó a medias.
 */
class DeviceProvisioningController extends Controller
{
    public function __construct(
        private readonly DeviceProvisioningOrchestrator $orchestrator,
        private readonly ProvisioningAuditor $auditor,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status'   => ['nullable', 'string', Rule::in(array_column(ProvisioningStatus::cases(), 'value'))],
            'active'   => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DeviceProvisioningSession::query()->with('agent')->orderByDesc('id');

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if ($request->boolean('active')) {
            $query->active();
        }

        $paginator = $query->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (DeviceProvisioningSession $s) => $s->toApiArray())
                ->all(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
        ]);
    }

    /**
     * Detalle de una sesión con sus tareas y su historial de auditoría.
     *
     * El historial se resuelve con `Audit::forRecord`, que ya existía: como el
     * `record_id` de este módulo es el id de la sesión, la traza completa del
     * alta sale de una sola consulta indexada.
     */
    public function show(int $id): JsonResponse
    {
        $session = DeviceProvisioningSession::with('agent')->findOrFail($id);

        $history = Audit::forRecord(ProvisioningAuditor::TABLE_SESSIONS, $session->id)
            ->orderBy('id')
            ->get()
            ->map(fn (Audit $a) => $a->toApiArray());

        return response()->json([
            'data' => array_merge($session->toApiArray(withTasks: true), [
                'audit_trail' => $history,
            ]),
        ]);
    }

    /**
     * Aprueba una sesión detenida en `awaiting_approval`.
     */
    public function approve(int $id): JsonResponse
    {
        $session = DeviceProvisioningSession::findOrFail($id);

        if (!$this->orchestrator->approve($session)) {
            return response()->json([
                'error' => [
                    'code'    => 'SESSION_NOT_AWAITING_APPROVAL',
                    'message' => "La sesión está en estado '{$session->status->value}' y no espera aprobación.",
                ],
            ], 409);
        }

        return response()->json(['data' => $session->fresh()->toApiArray()]);
    }

    /**
     * Cancela un alta. Si ya se había tocado algún extremo, la cancelación
     * dispara la reversión en lugar de cerrar la sesión sin más: dejarla a
     * medias es peor que no haberla empezado.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $session = DeviceProvisioningSession::findOrFail($id);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if (!$this->orchestrator->cancel($session, $validated['reason'] ?? null)) {
            return response()->json([
                'error' => [
                    'code'    => 'SESSION_ALREADY_FINISHED',
                    'message' => "La sesión ya terminó en estado '{$session->status->value}'.",
                ],
            ], 409);
        }

        return response()->json(['data' => $session->fresh()->toApiArray()]);
    }

    /**
     * Fuerza la reversión de una sesión que quedó colgada.
     *
     * Es la salida de emergencia cuando un agente murió sin reportar y el
     * vigilante todavía no ha vencido sus tareas.
     */
    public function rollback(Request $request, int $id): JsonResponse
    {
        $session = DeviceProvisioningSession::findOrFail($id);

        if ($session->status->isTerminal()) {
            return response()->json([
                'error' => [
                    'code'    => 'SESSION_ALREADY_FINISHED',
                    'message' => "La sesión ya terminó en estado '{$session->status->value}'.",
                ],
            ], 409);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->orchestrator->beginRollback(
            $session,
            'ROLLBACK_FORCED_BY_ADMIN',
            $validated['reason'] ?? 'Reversión forzada por un administrador desde el panel.',
        );

        return response()->json(['data' => $session->fresh()->toApiArray()]);
    }
}
