<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AgentRole;
use App\Enums\DeviceRole;
use App\Enums\DeviceVendor;
use App\Http\Controllers\Controller;
use App\Models\NetworkDevice;
use App\Models\NetworkLink;
use App\Models\NetworkScan;
use App\Models\NetworkScanFinding;
use App\Models\ProvisioningAgent;
use App\Services\Devices\ClientMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Barridos de descubrimiento y confirmación de lo que encuentran.
 *
 * El flujo tiene dos pasos a propósito. Un barrido devuelve todo lo que responda
 * en el rango —impresoras, portátiles, el equipo del vecino— y volcarlo al
 * inventario llenaría el mapa de ruido que después habría que limpiar a mano. El
 * operador confirma cuáles son suyos.
 *
 * El rango que se pide aquí **no autoriza nada por sí solo**: el agente lo valida
 * contra su propia lista blanca antes de barrer. Este controlador solo expresa
 * la intención.
 */
class NetworkScanController extends Controller
{
    /** Un barrido que lleva más de esto sin reportar se da por colgado. */
    private const STALE_AFTER_MINUTES = 10;

    public function index(): JsonResponse
    {
        $this->expireStale();

        $scans = NetworkScan::query()
            ->with(['agent:id,name', 'requester:id,nombre'])
            ->withCount('findings')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (NetworkScan $s) => $this->mapScan($s));

        return response()->json(['data' => $scans]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agent_id' => ['required', 'integer', 'exists:provisioning_agents,id'],
            // El CIDR se valida de forma y de tamaño; que se pueda barrer lo
            // decide el agente.
            'cidr'     => ['required', 'string', 'max:43'],
        ]);

        $agent = ProvisioningAgent::findOrFail($validated['agent_id']);

        if ($agent->role !== AgentRole::MONITOR) {
            return response()->json([
                'error' => [
                    'code'    => 'AGENT_ROLE_INVALID',
                    'message' => 'Solo un agente de monitoreo puede barrer la red.',
                ],
            ], 422);
        }

        if (!$this->looksLikeCidr($validated['cidr'])) {
            return response()->json([
                'error' => ['code' => 'CIDR_INVALID', 'message' => 'El rango no tiene forma de CIDR.'],
            ], 422);
        }

        $scan = NetworkScan::create([
            'agent_id'     => $agent->id,
            'cidr'         => $validated['cidr'],
            'status'       => NetworkScan::STATUS_PENDING,
            'requested_by' => Auth::id(),
        ]);

        return response()->json(['data' => $this->mapScan($scan->fresh(['agent', 'requester']))], 201);
    }

    public function show(int $id, ClientMatcher $matcher): JsonResponse
    {
        $scan = NetworkScan::with([
            'agent:id,name',
            'requester:id,nombre',
            'findings.matchedDevice:id,name',
            'findings.discoveredVia:id,name',
        ])
            ->withCount('findings')
            ->findOrFail($id);

        return response()->json(['data' => array_merge($this->mapScan($scan), [
            'findings' => $scan->findings->map(function (NetworkScanFinding $f) use ($matcher) {
                // La sugerencia se calcula al leer y no se guarda: un cliente
                // dado de alta después del barrido debe aparecer propuesto sin
                // tener que repetirlo.
                $cliente = $matcher->suggest($f);

                return [
                    'id'            => $f->id,
                    'source'        => $f->source,
                    'ip_address'    => $f->ip_address,
                    'mac_address'   => $f->mac_address,
                    'vendor'        => $f->vendor,
                    'model'         => $f->model,
                    'firmware'      => $f->firmware,
                    'hostname'      => $f->hostname,
                    'essid'         => $f->essid,
                    'known'         => $f->isKnown(),
                    'known_as'      => $f->matchedDevice?->name,
                    // De quién es vecino: el otro extremo del enlace del mapa.
                    'discovered_via'           => $f->discoveredVia?->name,
                    'discovered_via_device_id' => $f->discovered_via_device_id,
                    'remote_interface'         => $f->remote_interface,
                    'suggested_client_id'      => $cliente['client_id']   ?? null,
                    'suggested_client_name'    => $cliente['client_name'] ?? null,
                    'suggested_client_reason'  => $cliente['reason']      ?? null,
                ];
            })->all(),
        ])]);
    }

    /**
     * Convierte un hallazgo en un equipo del inventario.
     *
     * El operador elige el papel porque el barrido no puede saberlo: por la red
     * no se distingue una antena de enlace troncal de un sector de acceso, y de
     * ese dato dependen las alertas y el mapa.
     */
    public function adopt(Request $request, int $id): JsonResponse
    {
        $finding = NetworkScanFinding::findOrFail($id);

        if ($finding->isKnown()) {
            return response()->json([
                'error' => [
                    'code'    => 'ALREADY_KNOWN',
                    'message' => "Ese equipo ya está en el inventario como «{$finding->matchedDevice?->name}».",
                ],
            ], 422);
        }

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:100'],
            'role'     => ['required', Rule::enum(DeviceRole::class)],
            'username' => ['nullable', 'string', 'max:60'],
            'password' => ['nullable', 'string', 'max:255'],
            'credential_profile_id' => ['nullable', 'integer', 'exists:device_credentials,id'],
            'agent_id' => ['nullable', 'integer', 'exists:provisioning_agents,id'],
            // El abonado dueño del equipo. Solo tiene sentido en un CPE: una
            // antena sectorial de la torre no es «de» nadie.
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ]);

        $rol = DeviceRole::from($validated['role']);

        if (isset($validated['client_id']) && $rol !== DeviceRole::CPE) {
            return response()->json([
                'error' => [
                    'code'    => 'CLIENT_ONLY_ON_CPE',
                    'message' => 'Solo un equipo de abonado (CPE) puede vincularse a un cliente. '
                        . 'La infraestructura compartida no pertenece a un cliente concreto.',
                ],
            ], 422);
        }

        $vendor = DeviceVendor::tryFrom((string) $finding->vendor) ?? DeviceVendor::UBIQUITI;

        $device = NetworkDevice::create([
            'name'        => $validated['name'],
            'vendor'      => $vendor,
            'role'        => $rol,
            'driver'      => $vendor->defaultDriver(),
            'host'        => $finding->ip_address,
            'mac_address' => $finding->mac_address,
            'model'       => $finding->model,
            'firmware_version' => $finding->firmware,
            'username'    => $validated['username'] ?? null,
            'password'    => $validated['password'] ?? null,
            'credential_profile_id' => $validated['credential_profile_id'] ?? null,
            'client_id'   => $validated['client_id'] ?? null,
            // Por defecto lo sondea el mismo agente que lo encontró: es el que
            // demostró alcanzarlo.
            'agent_id'    => $validated['agent_id'] ?? $finding->scan->agent_id,
            'is_active'   => true,
            'is_monitored' => true,
            'provisioning_source' => 'scan',
        ]);

        $finding->update(['matched_device_id' => $device->id]);

        return response()->json(['data' => [
            'device_id' => $device->id,
            'link_id'   => $this->registrarEnlace($finding, $device)?->id,
            'ip_warning' => $this->avisoDeIpDiscordante($device),
        ]], 201);
    }

    /**
     * Registra el enlace con el equipo que reportó este hallazgo.
     *
     * Se hace solo, sin preguntar: si el hallazgo salió de la tabla de vecinos
     * de un router, ese router **es** el otro extremo del enlace —eso es lo que
     * significa ser vecino—. Pedirle al operador que lo indique a mano sería
     * pedirle que teclee un dato que el sistema ya tiene.
     *
     * Los hallazgos que solo vio el barrido UDP no llevan este dato y no
     * generan enlace: el mapa lo completará después el descubrimiento de
     * topología, que corre periódicamente.
     */
    private function registrarEnlace(NetworkScanFinding $finding, NetworkDevice $device): ?NetworkLink
    {
        if ($finding->discovered_via_device_id === null) {
            return null;
        }

        try {
            return NetworkLink::record(
                $finding->discovered_via_device_id,
                $device->id,
                'neighbor',
                array_filter([
                    'type'        => 'utp',
                    'a_interface' => $finding->remote_interface,
                ]),
            );
        } catch (\Throwable $e) {
            // Un enlace que no se puede registrar no invalida el alta: el equipo
            // ya está en el inventario y el mapa se rehace periódicamente.
            Log::warning('NetworkScanController: no se pudo registrar el enlace del alta.', [
                'finding_id' => $finding->id,
                'device_id'  => $device->id,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Avisa cuando la IP del equipo no coincide con la que tiene el cliente.
     *
     * `clients.ip` es la dirección con la que la sincronización de colas cobra a
     * ese abonado. Si el equipo que se acaba de vincular responde en otra, hay
     * dos verdades y una de las dos está mal. No se corrige sola —tocar esa
     * columna es tocar la facturación— pero callarlo dejaría el problema latente
     * hasta que alguien no pudiera cortar el servicio a quien no paga.
     */
    private function avisoDeIpDiscordante(NetworkDevice $device): ?string
    {
        $cliente = $device->client;

        if ($cliente === null || !$cliente->ip || $cliente->ip === '0.0.0.0') {
            return null;
        }

        if ($cliente->ip === $device->host) {
            return null;
        }

        return "El equipo responde en {$device->host} pero la ficha de «{$cliente->full_name}» "
            . "tiene {$cliente->ip}, que es la IP con la que se le factura. Revisa cuál es la buena.";
    }

    public function destroy(int $id): JsonResponse
    {
        NetworkScan::findOrFail($id)->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Cierra los barridos cuyo agente nunca reportó.
     *
     * Sin esto quedarían en «ejecutándose» para siempre y el operador no sabría
     * si esperar o volver a pedirlo. Se resuelve al listar en vez de con un
     * worker propio: es una comprobación de dos líneas y nadie mira esta
     * pantalla sin listar antes.
     */
    private function expireStale(): void
    {
        NetworkScan::query()
            ->where('status', NetworkScan::STATUS_RUNNING)
            ->where('started_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->update([
                'status'        => NetworkScan::STATUS_FAILED,
                'finished_at'   => now(),
                'error_code'    => 'AGENT_SILENT',
                'error_message' => 'El agente no reportó el resultado. ¿Sigue en marcha?',
            ]);
    }

    private function looksLikeCidr(string $value): bool
    {
        [$ip, $prefix] = array_pad(explode('/', $value, 2), 2, null);

        return filter_var($ip, FILTER_VALIDATE_IP) !== false
            && $prefix !== null
            && ctype_digit($prefix)
            && (int) $prefix >= 8
            && (int) $prefix <= 32;
    }

    /** @return array<string, mixed> */
    private function mapScan(NetworkScan $scan): array
    {
        return [
            'id'            => $scan->id,
            'cidr'          => $scan->cidr,
            'status'        => $scan->status,
            'agent'         => $scan->agent?->name,
            'agent_id'      => $scan->agent_id,
            'requested_by'  => $scan->requester?->nombre,
            'started_at'    => $scan->started_at?->toIso8601String(),
            'finished_at'   => $scan->finished_at?->toIso8601String(),
            'found_count'   => $scan->findings_count ?? $scan->found_count,
            'error_code'    => $scan->error_code,
            'error_message' => $scan->error_message,
            'created_at'    => $scan->created_at?->toIso8601String(),
        ];
    }
}
