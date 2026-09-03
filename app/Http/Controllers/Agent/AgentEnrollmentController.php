<?php

namespace App\Http\Controllers\Agent;

use App\Enums\AgentRole;
use App\Http\Controllers\Controller;
use App\Models\ProvisioningAgent;
use App\Services\Provisioning\AgentInstallerBuilder;
use App\Services\Provisioning\ProvisioningAuditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Canje del token de enrolamiento por las credenciales permanentes del agente.
 *
 * Es el único endpoint del canal M2M que NO va firmado — no puede estarlo,
 * porque el secreto con el que se firmaría es justo lo que se entrega aquí.
 * Lo que lo protege:
 *
 *  - el token es de 64 caracteres aleatorios y de un solo uso;
 *  - caduca a los 30 minutos;
 *  - se canjea dentro de una transacción con `lockForUpdate`, de modo que dos
 *    intentos simultáneos no pueden obtener credenciales distintas del mismo
 *    token;
 *  - la ruta va limitada por `throttle`;
 *  - el canje queda auditado con la IP de origen.
 *
 * Las credenciales se devuelven una única vez: en la fila solo queda el hash
 * del token y el secreto cifrado.
 */
class AgentEnrollmentController extends Controller
{
    public function __construct(private readonly ProvisioningAuditor $auditor)
    {
    }

    /**
     * Instalador desatendido de un agente.
     *
     * Lo descarga la máquina donde va a vivir el agente, no el panel, así que
     * no puede ir tras la sesión del administrador: lo protege una URL firmada
     * con la misma caducidad que el token que entrega.
     *
     * Cada descarga emite un token de enrolamiento **nuevo**, que invalida el
     * anterior. Es deliberado: así el token en claro solo existe dentro del
     * script y nunca viaja en la URL, donde acabaría en los registros del
     * servidor web y de cualquier proxy intermedio. La contrapartida es que
     * descargar dos veces deja muerto el primer script, y que hacerlo sobre un
     * agente ya enrolado lo desconecta — por eso queda auditado.
     */
    public function installer(Request $request, int $id, AgentInstallerBuilder $builder): Response
    {
        $agent = ProvisioningAgent::findOrFail($id);

        // La plataforma decide qué script se entrega. Se acepta lo que venga y
        // se degrada a Unix ante cualquier valor raro: la URL va firmada, así
        // que un valor manipulado solo consigue el instalador equivocado para
        // el mismo agente —no hay nada que ganar—, pero tampoco tiene sentido
        // fallar con un 422 sobre algo que se puede resolver con un defecto.
        $platform = (string) $request->query('platform', AgentInstallerBuilder::UNIX);

        if (!AgentInstallerBuilder::soporta($platform)) {
            $platform = AgentInstallerBuilder::UNIX;
        }

        $yaEnrolado = $agent->enrolled_at !== null;
        $token      = $agent->issueEnrollmentToken();

        $this->auditor->agent($agent, ProvisioningAuditor::AGENT_REVOKED, [
            'reason' => $yaEnrolado
                ? 'Se descargó el instalador: se emitió un token nuevo y las credenciales anteriores quedan inválidas.'
                : 'Se descargó el instalador y se emitió su token de enrolamiento.',
            'platform' => $platform,
        ]);

        return response($builder->build($agent, $token, $platform), 200, [
            // PowerShell no mira el tipo, pero un navegador que abra el enlace
            // sí: con `text/plain` lo enseñaría en pantalla, con el token
            // dentro, en el historial y en la caché del navegador.
            'Content-Type' => $platform === AgentInstallerBuilder::WINDOWS
                ? 'text/plain; charset=utf-8'
                : 'text/x-shellscript; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $builder->filename($agent, $platform) . '"',
            // El script lleva un token de un solo uso: no debe quedar en ninguna
            // caché intermedia.
            'Cache-Control'       => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function enroll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enrollment_token' => ['required', 'string', 'min:32', 'max:128'],
            'hostname'         => ['nullable', 'string', 'max:120'],
            'agent_version'    => ['nullable', 'string', 'max:30'],
            'capabilities'     => ['nullable', 'array'],
        ]);

        // Las credenciales en claro salen de la transacción como valor de
        // retorno y no adosadas al modelo: un atributo que no existe como
        // columna reventaría el siguiente save().
        [$agent, $credentials] = DB::transaction(function () use ($validated) {
            $candidate = ProvisioningAgent::findByEnrollmentToken($validated['enrollment_token']);

            if ($candidate === null) {
                return [null, null];
            }

            // Se recarga bajo bloqueo para que dos canjes simultáneos del mismo
            // token no generen dos juegos de credenciales.
            $locked = ProvisioningAgent::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || !$locked->hasPendingEnrollment()) {
                return [null, null];
            }

            return [$locked, $locked->completeEnrollment()];
        });

        if ($agent === null) {
            $this->auditor->authFailure('AGENT_ENROLLMENT_INVALID', null, [
                'hostname' => $validated['hostname'] ?? null,
            ]);

            return response()->json([
                'error' => [
                    'code'    => 'AGENT_ENROLLMENT_INVALID',
                    'message' => 'El token de enrolamiento no es válido o ha caducado.',
                ],
            ], 401);
        }

        $capabilityError = $this->validateCapabilities($agent->role, $validated['capabilities'] ?? []);
        if ($capabilityError !== null) {
            // El enrolamiento ya se consumió; se revierte dejando al agente sin
            // credenciales en vez de aceptar uno que no puede cumplir su rol.
            $agent->forceFill(['is_active' => false])->save();

            return response()->json([
                'error' => [
                    'code'    => 'AGENT_CAPABILITIES_INCOMPLETE',
                    'message' => $capabilityError,
                ],
            ], 422);
        }

        $agent->forceFill([
            'agent_version' => $validated['agent_version'] ?? null,
            'capabilities'  => $validated['capabilities'] ?? [],
            'last_seen_at'  => now(),
            'last_ip'       => $request->ip(),
        ])->save();

        $this->auditor->agent($agent, ProvisioningAuditor::AGENT_ENROLLED, [
            'hostname'      => $validated['hostname'] ?? null,
            'agent_version' => $validated['agent_version'] ?? null,
            'source_ip'     => $request->ip(),
        ]);

        return response()->json([
            'data' => [
                'agent_id'      => $agent->id,
                'name'          => $agent->name,
                'role'          => $agent->role->value,
                // Única vez que estos valores existen fuera del agente.
                'token'         => $credentials['token'],
                'secret'        => $credentials['secret'],
                'poll_interval' => config('provisioning.agent.poll_interval_seconds'),
            ],
        ], 201);
    }

    /**
     * Un agente `vpn_host` que no publique los datos de su servidor WireGuard
     * es inservible: la saga no podría decirle al router a dónde marcar ni con
     * qué clave pública. Se rechaza en el enrolamiento y no a mitad de un alta.
     */
    private function validateCapabilities(AgentRole $role, array $capabilities): ?string
    {
        if ($role !== AgentRole::VPN_HOST) {
            return null;
        }

        $required = ['server_public_key', 'endpoint_host', 'endpoint_port', 'interface', 'subnet'];
        $missing  = array_values(array_filter(
            $required,
            fn (string $key) => blank($capabilities[$key] ?? null)
        ));

        if ($missing === []) {
            return null;
        }

        return 'El agente de VPN debe publicar: ' . implode(', ', $missing) . '.';
    }
}
