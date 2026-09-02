<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AgentRole;
use App\Http\Controllers\Controller;
use App\Models\ProvisioningAgent;
use App\Services\Provisioning\ProvisioningAuditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * Alta y gobierno de los agentes de aprovisionamiento desde el panel.
 *
 * Registrar un agente es entregarle la capacidad de tocar la infraestructura de
 * red, así que todas las operaciones de escritura van tras `super_admin` y
 * quedan auditadas. El token de enrolamiento se muestra una única vez: no se
 * guarda en claro y no hay forma de recuperarlo, solo de regenerarlo.
 */
class ProvisioningAgentController extends Controller
{
    public function __construct(private readonly ProvisioningAuditor $auditor)
    {
    }

    public function index(): JsonResponse
    {
        $agents = ProvisioningAgent::query()
            ->orderBy('role')
            ->orderBy('name')
            ->get()
            ->map(fn (ProvisioningAgent $a) => $a->toApiArray());

        return response()->json(['data' => $agents]);
    }

    /**
     * Crea el registro del agente y devuelve su token de enrolamiento.
     *
     * El agente todavía no existe como tal: hasta que canjee el token no tiene
     * credenciales y no puede autenticarse.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'role' => ['required', Rule::in(array_column(AgentRole::cases(), 'value'))],
        ]);

        $agent = ProvisioningAgent::create([
            'name'      => $validated['name'],
            'role'      => $validated['role'],
            'is_active' => true,
        ]);

        $token = $agent->issueEnrollmentToken();

        return response()->json([
            'data' => array_merge($agent->fresh()->toApiArray(), [
                // Única vez que este valor se muestra.
                'enrollment_token'   => $token,
                'enrollment_expires' => $agent->enrollment_expires_at?->toIso8601String(),
                'enroll_command'     => $this->enrollCommand($token),
                'installer_command'  => $this->installerCommand($agent),
            ]),
        ], 201);
    }

    /**
     * Regenera el token de enrolamiento. Invalida las credenciales actuales:
     * un agente ya enrolado deja de poder autenticarse hasta que vuelva a
     * canjear. Es la vía para rotar el secreto de un agente comprometido.
     */
    public function regenerateToken(int $id): JsonResponse
    {
        $agent = ProvisioningAgent::findOrFail($id);
        $token = $agent->issueEnrollmentToken();

        $this->auditor->agent($agent, ProvisioningAuditor::AGENT_REVOKED, [
            'reason' => 'Se regeneró el token de enrolamiento; las credenciales anteriores quedan inválidas.',
        ]);

        return response()->json([
            'data' => array_merge($agent->fresh()->toApiArray(), [
                'enrollment_token'   => $token,
                'enrollment_expires' => $agent->enrollment_expires_at?->toIso8601String(),
                'enroll_command'     => $this->enrollCommand($token),
                'installer_command'  => $this->installerCommand($agent),
            ]),
        ]);
    }

    /**
     * Revoca o reactiva un agente. La revocación es efectiva de inmediato: el
     * middleware la comprueba en cada petición.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $agent = ProvisioningAgent::findOrFail($id);

        $validated = $request->validate([
            'name'      => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $wasActive = $agent->is_active;
        $agent->update($validated);

        if ($wasActive && $agent->is_active === false) {
            $this->auditor->agent($agent, ProvisioningAuditor::AGENT_REVOKED, [
                'reason' => 'Revocado desde el panel.',
            ]);
        }

        return response()->json(['data' => $agent->fresh()->toApiArray()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $agent = ProvisioningAgent::findOrFail($id);

        // Las tareas y sesiones referencian al agente con `restrictOnDelete`
        // para no perder la trazabilidad de quién hizo qué. Un agente con
        // historial se desactiva, no se borra.
        if ($agent->tasks()->exists() || $agent->sessions()->exists()) {
            return response()->json([
                'error' => [
                    'code'    => 'AGENT_HAS_HISTORY',
                    'message' => 'El agente tiene altas registradas y no puede eliminarse. '
                        . 'Desactívalo para revocar su acceso conservando la trazabilidad.',
                ],
            ], 409);
        }

        $this->auditor->agent($agent, ProvisioningAuditor::AGENT_REVOKED, [
            'reason' => 'Eliminado desde el panel (sin historial asociado).',
        ]);

        $agent->delete();

        return response()->json(['data' => null], 204);
    }

    private function enrollCommand(string $token): string
    {
        $url = rtrim((string) config('app.url'), '/');

        return "ispgestor-agent enroll --url {$url} --token {$token}";
    }

    /**
     * Orden única que instala, enrola y arranca el agente en la máquina destino.
     *
     * Es el camino recomendado: el manual exige llevar la carpeta del agente a
     * mano, ejecutar `install.sh`, averiguar qué NIC vigilar y pegar el token,
     * y cada uno de esos pasos es una ocasión de equivocarse.
     *
     * La URL va firmada y caduca igual que el token. No se le adjunta el token
     * en claro: quien la descarga recibe uno recién emitido, así el secreto no
     * pasa por la barra de direcciones ni por los registros de ningún proxy.
     */
    private function installerCommand(ProvisioningAgent $agent): string
    {
        $url = URL::temporarySignedRoute(
            'agent.installer',
            now()->addMinutes(ProvisioningAgent::ENROLLMENT_TTL_MINUTES),
            ['id' => $agent->id]
        );

        return "curl -fsSL \"{$url}\" | sudo bash";
    }
}
