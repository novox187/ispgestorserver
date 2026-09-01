<?php

namespace App\Http\Controllers\Agent;

use App\Enums\AgentRole;
use App\Enums\ProvisioningStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateProvisioningAgent;
use App\Jobs\AdvanceProvisioningSession;
use App\Models\DeviceProvisioningSession;
use App\Models\ProvisioningAgent;
use App\Services\Provisioning\ProvisioningAuditor;
use App\Services\Provisioning\ProvisioningSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Recibe el aviso de que un dispositivo se ha conectado físicamente.
 *
 * El agente `provisioner` combina tres señales para detectarlo —MNDP en el
 * puerto UDP 5678, el estado del carrier de la NIC y una sonda directa— y
 * reporta aquí lo que haya conseguido averiguar.
 *
 * El endpoint es idempotente por diseño: MNDP se repite cada 60 segundos, así
 * que el mismo equipo llegará muchas veces mientras dura su alta. Un reporte
 * repetido devuelve la sesión que ya estaba abierta en lugar de encadenar
 * intentos duplicados sobre el mismo router.
 */
class DeviceDetectionController extends Controller
{
    public function __construct(
        private readonly ProvisioningAuditor $auditor,
        private readonly ProvisioningSettings $settings,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $agent = $this->agent($request);

        if ($agent->role !== AgentRole::PROVISIONER) {
            return response()->json([
                'error' => [
                    'code'    => 'AGENT_ROLE_FORBIDDEN',
                    'message' => 'Solo un agente de aprovisionamiento puede reportar detecciones.',
                ],
            ], 403);
        }

        $validated = $request->validate([
            'detection_method'  => ['required', 'string', 'in:mndp,link_probe,arp,manual'],
            'mac_address'       => ['nullable', 'string', 'max:32'],
            'identity'          => ['nullable', 'string', 'max:100'],
            'board_name'        => ['nullable', 'string', 'max:100'],
            'routeros_version'  => ['nullable', 'string', 'max:30'],
            'serial_number'     => ['nullable', 'string', 'max:60'],
            'link_interface'    => ['nullable', 'string', 'max:30'],
            'lan_ip'            => ['nullable', 'ip'],
        ]);

        if (!$this->settings->enabled()) {
            return $this->ignored('PROVISIONING_DISABLED',
                'El aprovisionamiento automático está desactivado.');
        }

        $mac = $this->normalizeMac($validated['mac_address'] ?? null);

        if (!$this->settings->macIsAllowed($mac)) {
            // No se abre sesión ni se audita como incidente: enchufar un equipo
            // de otro fabricante en el banco es normal y no es un intento de
            // intrusión. Basta con que el agente sepa que se descartó.
            return $this->ignored('MAC_NOT_ALLOWED',
                'La MAC no pertenece a un fabricante admitido para el alta automática.');
        }

        $serial = $validated['serial_number'] ?? null;

        // Sesión y deduplicación dentro de la misma transacción: dos reportes
        // simultáneos del mismo equipo (MNDP más sonda de carrier) no deben
        // abrir dos altas.
        [$session, $isNew] = DB::transaction(function () use ($agent, $validated, $mac, $serial) {
            $existing = DeviceProvisioningSession::activeForDevice($mac, $serial);

            if ($existing !== null) {
                // Se enriquece con lo que este reporte aporte de nuevo: la
                // sonda directa suele traer datos que MNDP no lleva.
                $existing->forceFill(array_filter([
                    'identity'         => $validated['identity']         ?? null,
                    'board_name'       => $validated['board_name']       ?? null,
                    'routeros_version' => $validated['routeros_version'] ?? null,
                    'serial_number'    => $serial,
                    'lan_ip'           => $validated['lan_ip']           ?? null,
                ], fn ($v) => $v !== null))->save();

                return [$existing, false];
            }

            $created = DeviceProvisioningSession::create([
                'agent_id'         => $agent->id,
                'status'           => ProvisioningStatus::DETECTED,
                'detection_method' => $validated['detection_method'],
                'mac_address'      => $mac,
                'identity'         => $validated['identity']         ?? null,
                'board_name'       => $validated['board_name']       ?? null,
                'routeros_version' => $validated['routeros_version'] ?? null,
                'serial_number'    => $serial,
                'link_interface'   => $validated['link_interface']   ?? null,
                'lan_ip'           => $validated['lan_ip']           ?? null,
                'started_at'       => now(),
            ]);

            return [$created, true];
        });

        if ($isNew) {
            $this->auditor->session($session, ProvisioningAuditor::DETECTED, [
                'detection_method' => $session->detection_method,
                'link_interface'   => $session->link_interface,
                'lan_ip'           => $session->lan_ip,
            ], agent: $agent);

            AdvanceProvisioningSession::dispatch($session->id);
        }

        return response()->json([
            'data' => [
                'session_id' => $session->id,
                'status'     => $session->status->value,
                'created'    => $isNew,
            ],
        ], $isNew ? 201 : 200);
    }

    /**
     * Normaliza a AA:BB:CC:DD:EE:FF. MNDP entrega los bytes crudos y la sonda
     * ARP suele dar minúsculas con guiones; sin normalizar, el mismo equipo
     * abriría una sesión por cada formato.
     */
    private function normalizeMac(?string $mac): ?string
    {
        if ($mac === null) {
            return null;
        }

        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac) ?? '');

        if (strlen($hex) !== 12) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }

    private function ignored(string $code, string $message): JsonResponse
    {
        return response()->json([
            'data' => [
                'session_id' => null,
                'created'    => false,
                'ignored'    => true,
                'code'       => $code,
                'message'    => $message,
            ],
        ], 202);
    }

    private function agent(Request $request): ProvisioningAgent
    {
        /** @var ProvisioningAgent $agent */
        $agent = $request->attributes->get(AuthenticateProvisioningAgent::REQUEST_ATTRIBUTE);

        return $agent;
    }
}
