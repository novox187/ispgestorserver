<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\FirewallApplyLog;
use App\Models\MikrotikRouter;
use App\Services\MikroTikService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RouterOS\Client;
use RouterOS\Query;
use Throwable;

class FirewallController extends Controller
{
    /**
     * GET /api/mikrotik/firewall/snapshot?router_id=<id>
     * Devuelve el estado persistido de las reglas para el router dado.
     */
    public function snapshot(Request $request): JsonResponse
    {
        $request->validate([
            'router_id' => 'required|integer|exists:mikrotik_routers,id',
        ]);

        $router = MikrotikRouter::findOrFail($request->router_id);

        $filters = $router->filterRules()
            ->orderBy('priority')
            ->get()
            ->map(fn($r) => $r->toFrontend())
            ->values();

        $nat = $router->natRules()
            ->orderBy('priority')
            ->get()
            ->map(fn($r) => $r->toFrontend())
            ->values();

        return response()->json([
            'data' => [
                'routerId'  => (string) $router->id,
                'loadedAt'  => $router->last_loaded_at  ? (int) ($router->last_loaded_at->timestamp  * 1000) : null,
                'appliedAt' => $router->last_applied_at ? (int) ($router->last_applied_at->timestamp * 1000) : null,
                'filters'   => $filters,
                'nat'       => $nat,
            ],
        ]);
    }

    /**
     * POST /api/mikrotik/firewall/apply
     * Persiste el snapshot en DB y lo empuja al router MikroTik.
     */
    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'router_id'        => 'required|integer|exists:mikrotik_routers,id',
            'reason'           => 'nullable|string|max:500',
            'snapshot'         => 'required|array',
            'snapshot.filters' => 'required|array',
            'snapshot.nat'     => 'required|array',

            // Reglas de filtro
            'snapshot.filters.*.id'             => 'required|string',
            'snapshot.filters.*.enabled'        => 'required|boolean',
            'snapshot.filters.*.chain'          => 'required|in:input,output,forward',
            'snapshot.filters.*.action'         => 'required|in:accept,drop,reject',
            'snapshot.filters.*.protocol'       => 'required|in:any,tcp,udp,icmp',
            'snapshot.filters.*.srcAddress'     => 'nullable|string',
            'snapshot.filters.*.srcAddressList' => 'nullable|string|max:64',
            'snapshot.filters.*.dstAddress'     => 'nullable|string',
            'snapshot.filters.*.srcPort'        => 'nullable|string',
            'snapshot.filters.*.dstPort'        => 'nullable|string',
            'snapshot.filters.*.inInterface'    => 'nullable|string',
            'snapshot.filters.*.outInterface'   => 'nullable|string',
            'snapshot.filters.*.comment'        => 'nullable|string',
            'snapshot.filters.*.log'            => 'nullable|boolean',

            // Reglas NAT
            'snapshot.nat.*.id'             => 'required|string',
            'snapshot.nat.*.enabled'        => 'required|boolean',
            'snapshot.nat.*.chain'          => 'required|in:srcnat,dstnat',
            'snapshot.nat.*.action'         => 'required|in:masquerade,src-nat,dst-nat,redirect',
            'snapshot.nat.*.protocol'       => 'required|in:any,tcp,udp,icmp',
            'snapshot.nat.*.srcAddress'     => 'nullable|string',
            'snapshot.nat.*.srcAddressList' => 'nullable|string|max:64',
            'snapshot.nat.*.dstAddress'     => 'nullable|string',
            'snapshot.nat.*.srcPort'        => 'nullable|string',
            'snapshot.nat.*.dstPort'        => 'nullable|string',
            'snapshot.nat.*.outInterface'   => 'nullable|string',
            'snapshot.nat.*.toAddresses'    => 'nullable|string',
            'snapshot.nat.*.toPorts'        => 'nullable|string',
            'snapshot.nat.*.comment'        => 'nullable|string',
            'snapshot.nat.*.log'            => 'nullable|boolean',
        ]);

        $router   = MikrotikRouter::findOrFail($validated['router_id']);
        $snapshot = $validated['snapshot'];

        // Capturar estado anterior para auditoría
        $snapshotBefore = [
            'filters' => $router->filterRules()->orderBy('priority')->get()->map(fn($r) => $r->toFrontend())->values(),
            'nat'     => $router->natRules()->orderBy('priority')->get()->map(fn($r) => $r->toFrontend())->values(),
        ];

        $appliedAt = now();

        // Persistir en DB (reemplaza todas las reglas del router)
        DB::transaction(function () use ($router, $snapshot, $appliedAt) {
            $router->filterRules()->delete();
            $router->natRules()->delete();

            foreach ($snapshot['filters'] as $idx => $rule) {
                $router->filterRules()->create([
                    'uuid'             => $rule['id'],
                    'enabled'          => $rule['enabled'],
                    'priority'         => $idx + 1,
                    'chain'            => $rule['chain'],
                    'action'           => $rule['action'],
                    'protocol'         => $rule['protocol'] ?? 'any',
                    'src_address'      => $rule['srcAddress']     ?? null,
                    'src_address_list' => $rule['srcAddressList'] ?? null,
                    'dst_address'      => $rule['dstAddress']     ?? null,
                    'src_port'         => $rule['srcPort']        ?? null,
                    'dst_port'         => $rule['dstPort']        ?? null,
                    'in_interface'     => $rule['inInterface']    ?? null,
                    'out_interface'    => $rule['outInterface']   ?? null,
                    'comment'          => $rule['comment']        ?? null,
                    'log'              => $rule['log']            ?? false,
                ]);
            }

            foreach ($snapshot['nat'] as $idx => $rule) {
                $router->natRules()->create([
                    'uuid'             => $rule['id'],
                    'enabled'          => $rule['enabled'],
                    'priority'         => $idx + 1,
                    'chain'            => $rule['chain'],
                    'action'           => $rule['action'],
                    'protocol'         => $rule['protocol'] ?? 'any',
                    'src_address'      => $rule['srcAddress']     ?? null,
                    'src_address_list' => $rule['srcAddressList'] ?? null,
                    'dst_address'      => $rule['dstAddress']     ?? null,
                    'src_port'         => $rule['srcPort']        ?? null,
                    'dst_port'         => $rule['dstPort']        ?? null,
                    'out_interface'    => $rule['outInterface']   ?? null,
                    'to_addresses'     => $rule['toAddresses']    ?? null,
                    'to_ports'         => $rule['toPorts']        ?? null,
                    'comment'          => $rule['comment']        ?? null,
                    'log'              => $rule['log']            ?? false,
                ]);
            }

            $router->update(['last_applied_at' => $appliedAt]);
        });

        // Empujar reglas al router MikroTik
        $pushStatus = 'success';
        $errorMessage = null;

        if ($router->is_active) {
            $service = null;
            try {
                $client = new Client([
                    'host'    => $router->host,
                    'user'    => $router->username,
                    'pass'    => $router->password,
                    'port'    => $router->port,
                    'timeout' => 10,
                ]);

                $service = new MikroTikService($client);

                $filterRules = $router->filterRules()->orderBy('priority')->get()->toArray();
                $natRules    = $router->natRules()->orderBy('priority')->get()->toArray();

                $service->syncFilterRules($filterRules);
                $service->syncNatRules($natRules);

            } catch (Throwable $e) {
                $pushStatus   = 'failed';
                $errorMessage = $e->getMessage();
                Log::error('FirewallController@apply MikroTik push failed: ' . $e->getMessage());
            } finally {
                // Cierra la sesión API antes de que PHP libere el socket
                if ($service) $service->disconnect();
                unset($service, $client);
            }
        }

        // Registrar la operación
        $employeeId = auth()->user() instanceof Employee ? auth()->id() : null;

        FirewallApplyLog::create([
            'router_id'        => $router->id,
            'employee_id'      => $employeeId,
            'reason'           => $validated['reason'] ?? null,
            'snapshot_before'  => $snapshotBefore,
            'snapshot_applied' => $snapshot,
            'status'           => $pushStatus,
            'error_message'    => $errorMessage,
            'applied_at'       => $appliedAt,
        ]);

        if ($pushStatus === 'failed') {
            return response()->json([
                'message' => 'Las reglas se guardaron en la base de datos pero falló la conexión al router MikroTik.',
                'error'   => $errorMessage,
            ], 502);
        }

        return response()->json([
            'data' => [
                'applied_at' => (int) ($appliedAt->timestamp * 1000),
            ],
        ]);
    }

    /**
     * POST /api/mikrotik/firewall/sync/from-router
     * Lee las reglas vivas del router y las persiste en la BD.
     */
    public function syncFromRouter(Request $request): JsonResponse
    {
        $request->validate([
            'router_id' => 'required|integer|exists:mikrotik_routers,id',
        ]);

        $router = MikrotikRouter::findOrFail($request->router_id);

        $rawFilters = [];
        $rawNat     = [];
        $service    = null;
        try {
            $client = new Client([
                'host'    => $router->host,
                'user'    => $router->username,
                'pass'    => $router->password,
                'port'    => $router->port,
                'timeout' => 10,
            ]);

            $service    = new MikroTikService($client);
            $rawFilters = $service->getFirewallFilterRules();
            $rawNat     = $service->getFirewallNatRules();

        } catch (Throwable $e) {
            Log::error('FirewallController@syncFromRouter connect failed: ' . $e->getMessage());
            return response()->json(['message' => 'No se pudo conectar al router: ' . $e->getMessage()], 502);
        } finally {
            if ($service) $service->disconnect();
            unset($service, $client);
        }

        $validChains     = ['input', 'output', 'forward'];
        $validActions    = ['accept', 'drop', 'reject'];
        $validProtocols  = ['any', 'tcp', 'udp', 'icmp'];
        $validNatChains  = ['srcnat', 'dstnat'];
        $validNatActions = ['masquerade', 'src-nat', 'dst-nat', 'redirect'];

        $loadedAt = now();

        DB::transaction(function () use (
            $router, $rawFilters, $rawNat,
            $validChains, $validActions, $validProtocols,
            $validNatChains, $validNatActions, $loadedAt
        ) {
            $router->filterRules()->delete();
            $router->natRules()->delete();

            foreach ($rawFilters as $idx => $raw) {
                $chain    = $raw['chain']    ?? '';
                $action   = $raw['action']   ?? '';
                $protocol = $raw['protocol'] ?? 'any';

                if (!in_array($chain, ['input', 'output', 'forward'], true)) continue;
                if (!in_array($action, ['accept', 'drop', 'reject'], true))  continue;
                if (!in_array($protocol, ['any', 'tcp', 'udp', 'icmp'], true)) $protocol = 'any';

                $router->filterRules()->create([
                    'uuid'             => Str::uuid(),
                    'external_id'      => $raw['.id'] ?? null,
                    'enabled'          => ($raw['disabled'] ?? 'false') !== 'true',
                    'priority'         => $idx + 1,
                    'chain'            => $chain,
                    'action'           => $action,
                    'protocol'         => $protocol,
                    'src_address'      => $raw['src-address']      ?? null,
                    'src_address_list' => $raw['src-address-list'] ?? null,
                    'dst_address'      => $raw['dst-address']      ?? null,
                    'src_port'         => $raw['src-port']         ?? null,
                    'dst_port'         => $raw['dst-port']         ?? null,
                    'in_interface'     => $raw['in-interface']     ?? null,
                    'out_interface'    => $raw['out-interface']    ?? null,
                    'comment'          => $raw['comment']          ?? null,
                    'log'              => ($raw['log'] ?? 'false') === 'yes',
                ]);
            }

            foreach ($rawNat as $idx => $raw) {
                $chain    = $raw['chain']    ?? '';
                $action   = $raw['action']   ?? '';
                $protocol = $raw['protocol'] ?? 'any';

                if (!in_array($chain, ['srcnat', 'dstnat'], true))                      continue;
                if (!in_array($action, ['masquerade', 'src-nat', 'dst-nat', 'redirect'], true)) continue;
                if (!in_array($protocol, ['any', 'tcp', 'udp', 'icmp'], true)) $protocol = 'any';

                $router->natRules()->create([
                    'uuid'             => Str::uuid(),
                    'external_id'      => $raw['.id']              ?? null,
                    'enabled'          => ($raw['disabled'] ?? 'false') !== 'true',
                    'priority'         => $idx + 1,
                    'chain'            => $chain,
                    'action'           => $action,
                    'protocol'         => $protocol,
                    'src_address'      => $raw['src-address']      ?? null,
                    'src_address_list' => $raw['src-address-list'] ?? null,
                    'dst_address'      => $raw['dst-address']      ?? null,
                    'src_port'         => $raw['src-port']         ?? null,
                    'dst_port'         => $raw['dst-port']         ?? null,
                    'out_interface'    => $raw['out-interface']    ?? null,
                    'to_addresses'     => $raw['to-addresses']     ?? null,
                    'to_ports'         => $raw['to-ports']         ?? null,
                    'comment'          => $raw['comment']          ?? null,
                    'log'              => ($raw['log'] ?? 'false') === 'yes',
                ]);
            }

            $router->update(['last_loaded_at' => $loadedAt]);
        });

        // Construir snapshot en formato frontend para devolver
        $filters = $router->filterRules()->orderBy('priority')->get()->map(fn($r) => $r->toFrontend())->values();
        $nat     = $router->natRules()->orderBy('priority')->get()->map(fn($r) => $r->toFrontend())->values();

        return response()->json([
            'data' => [
                'routerId'  => (string) $router->id,
                'loadedAt'  => (int) ($loadedAt->timestamp * 1000),
                'appliedAt' => $router->last_applied_at ? (int) ($router->last_applied_at->timestamp * 1000) : null,
                'filters'   => $filters,
                'nat'       => $nat,
            ],
        ]);
    }

    /**
     * POST /api/mikrotik/firewall/validate
     * Valida un snapshot antes de aplicarlo. Devuelve errors y warnings.
     */
    public function validate(Request $request): JsonResponse
    {
        $request->validate([
            'router_id'        => 'required|integer|exists:mikrotik_routers,id',
            'snapshot'         => 'required|array',
            'snapshot.filters' => 'required|array',
            'snapshot.nat'     => 'required|array',
        ]);

        $errors   = [];
        $warnings = [];

        // Puertos solo válidos con TCP/UDP
        foreach ($request->input('snapshot.filters', []) as $idx => $rule) {
            $id    = $rule['id'] ?? "filter-{$idx}";
            $prio  = $rule['priority'] ?? ($idx + 1);
            $proto = $rule['protocol'] ?? 'any';

            if ((!empty($rule['srcPort']) || !empty($rule['dstPort'])) && !in_array($proto, ['tcp', 'udp'])) {
                $warnings[] = ['ruleId' => $id, 'field' => 'protocol',
                    'message' => "Filtro #$prio: tiene puerto definido pero el protocolo es '$proto'. Los puertos solo funcionan con TCP/UDP."];
            }
        }

        foreach ($request->input('snapshot.nat', []) as $idx => $rule) {
            $id     = $rule['id'] ?? "nat-{$idx}";
            $prio   = $rule['priority'] ?? ($idx + 1);
            $proto  = $rule['protocol'] ?? 'any';
            $action = $rule['action'] ?? '';

            if ((!empty($rule['srcPort']) || !empty($rule['dstPort'])) && !in_array($proto, ['tcp', 'udp'])) {
                $warnings[] = ['ruleId' => $id, 'field' => 'protocol',
                    'message' => "NAT #$prio: tiene puerto definido pero el protocolo es '$proto'. Los puertos solo funcionan con TCP/UDP."];
            }
            if ($action === 'src-nat' && empty($rule['toAddresses'])) {
                $warnings[] = ['ruleId' => $id, 'field' => 'toAddresses',
                    'message' => "NAT #$prio: acción 'src-nat' requiere 'to-addresses' definido."];
            }
            if ($action === 'dst-nat' && empty($rule['toAddresses'])) {
                $warnings[] = ['ruleId' => $id, 'field' => 'toAddresses',
                    'message' => "NAT #$prio: acción 'dst-nat' generalmente requiere 'to-addresses'."];
            }
        }

        // Detectar duplicados en filtros (mismo chain+action+protocolo+match)
        $filterSigs = [];
        foreach ($request->input('snapshot.filters', []) as $idx => $rule) {
            $id   = $rule['id'] ?? "filter-{$idx}";
            $prio = $rule['priority'] ?? ($idx + 1);
            $sig  = implode('|', [
                $rule['chain']          ?? '',
                $rule['action']         ?? '',
                $rule['protocol']       ?? 'any',
                $rule['srcAddress']     ?? '',
                $rule['dstAddress']     ?? '',
                $rule['srcPort']        ?? '',
                $rule['dstPort']        ?? '',
                $rule['inInterface']    ?? '',
                $rule['outInterface']   ?? '',
            ]);
            if (isset($filterSigs[$sig])) {
                $warnings[] = ['ruleId' => $id, 'field' => 'chain',
                    'message' => "Filtro #$prio: posible duplicado de la regla #{$filterSigs[$sig]}."];
            } else {
                $filterSigs[$sig] = $prio;
            }
        }

        return response()->json([
            'data' => [
                'valid'    => empty($errors),
                'errors'   => $errors,
                'warnings' => $warnings,
            ],
        ]);
    }

    /**
     * GET /api/mikrotik/firewall/apply-logs?router_id=X&per_page=15
     */
    public function applyLogs(Request $request): JsonResponse
    {
        $request->validate([
            'router_id' => 'required|integer|exists:mikrotik_routers,id',
            'per_page'  => 'nullable|integer|min:5|max:50',
        ]);

        $logs = FirewallApplyLog::where('router_id', $request->router_id)
            ->orderByDesc('applied_at')
            ->paginate((int) $request->input('per_page', 15));

        return response()->json([
            'data' => $logs->map(fn($log) => [
                'id'           => $log->id,
                'routerId'     => $log->router_id,
                'employeeId'   => $log->employee_id,
                'reason'       => $log->reason,
                'status'       => $log->status,
                'errorMessage' => $log->error_message,
                'filterCount'  => count((array) ($log->snapshot_applied['filters'] ?? [])),
                'natCount'     => count((array) ($log->snapshot_applied['nat'] ?? [])),
                'appliedAt'    => (int) ($log->applied_at->timestamp * 1000),
                'hasBefore'    => !empty($log->snapshot_before),
            ])->values(),
            'meta' => [
                'currentPage' => $logs->currentPage(),
                'lastPage'    => $logs->lastPage(),
                'total'       => $logs->total(),
            ],
        ]);
    }

    /**
     * POST /api/mikrotik/firewall/apply-logs/{id}/rollback
     * Restaura el estado anterior registrado en un log de apply.
     */
    public function rollback(Request $request, int $logId): JsonResponse
    {
        $log = FirewallApplyLog::findOrFail($logId);

        if (empty($log->snapshot_before)) {
            return response()->json(['message' => 'Este log no tiene snapshot previo disponible para restaurar.'], 422);
        }

        $router   = MikrotikRouter::findOrFail($log->router_id);
        $snapshot = $log->snapshot_before;

        $snapshotBefore = [
            'filters' => $router->filterRules()->orderBy('priority')->get()->map(fn($r) => $r->toFrontend())->values(),
            'nat'     => $router->natRules()->orderBy('priority')->get()->map(fn($r) => $r->toFrontend())->values(),
        ];

        $appliedAt = now();

        DB::transaction(function () use ($router, $snapshot, $appliedAt) {
            $router->filterRules()->delete();
            $router->natRules()->delete();

            foreach (($snapshot['filters'] ?? []) as $idx => $rule) {
                $router->filterRules()->create([
                    'uuid'             => $rule['id'],
                    'enabled'          => $rule['enabled']          ?? true,
                    'priority'         => $idx + 1,
                    'chain'            => $rule['chain'],
                    'action'           => $rule['action'],
                    'protocol'         => $rule['protocol']         ?? 'any',
                    'src_address'      => $rule['srcAddress']       ?? null,
                    'src_address_list' => $rule['srcAddressList']   ?? null,
                    'dst_address'      => $rule['dstAddress']       ?? null,
                    'src_port'         => $rule['srcPort']          ?? null,
                    'dst_port'         => $rule['dstPort']          ?? null,
                    'in_interface'     => $rule['inInterface']      ?? null,
                    'out_interface'    => $rule['outInterface']     ?? null,
                    'comment'          => $rule['comment']          ?? null,
                    'log'              => $rule['log']              ?? false,
                ]);
            }

            foreach (($snapshot['nat'] ?? []) as $idx => $rule) {
                $router->natRules()->create([
                    'uuid'             => $rule['id'],
                    'enabled'          => $rule['enabled']          ?? true,
                    'priority'         => $idx + 1,
                    'chain'            => $rule['chain'],
                    'action'           => $rule['action'],
                    'protocol'         => $rule['protocol']         ?? 'any',
                    'src_address'      => $rule['srcAddress']       ?? null,
                    'src_address_list' => $rule['srcAddressList']   ?? null,
                    'dst_address'      => $rule['dstAddress']       ?? null,
                    'src_port'         => $rule['srcPort']          ?? null,
                    'dst_port'         => $rule['dstPort']          ?? null,
                    'out_interface'    => $rule['outInterface']     ?? null,
                    'to_addresses'     => $rule['toAddresses']      ?? null,
                    'to_ports'         => $rule['toPorts']          ?? null,
                    'comment'          => $rule['comment']          ?? null,
                    'log'              => $rule['log']              ?? false,
                ]);
            }

            $router->update(['last_applied_at' => $appliedAt]);
        });

        // Intentar sincronizar al router físico
        $pushStatus   = 'success';
        $errorMessage = null;
        $service      = null;

        if ($router->is_active) {
            try {
                $client  = new Client([
                    'host' => $router->host, 'user' => $router->username,
                    'pass' => $router->password, 'port' => $router->port, 'timeout' => 10,
                ]);
                $service = new MikroTikService($client);
                $service->syncFilterRules($router->filterRules()->orderBy('priority')->get()->toArray());
                $service->syncNatRules($router->natRules()->orderBy('priority')->get()->toArray());
            } catch (Throwable $e) {
                $pushStatus   = 'failed';
                $errorMessage = $e->getMessage();
                Log::error("FirewallController@rollback push failed: {$e->getMessage()}");
            } finally {
                if ($service) $service->disconnect();
                unset($service, $client);
            }
        }

        $employeeId = auth()->user() instanceof Employee ? auth()->id() : null;

        FirewallApplyLog::create([
            'router_id'        => $router->id,
            'employee_id'      => $employeeId,
            'reason'           => "Rollback al estado del log #{$logId}",
            'snapshot_before'  => $snapshotBefore,
            'snapshot_applied' => $snapshot,
            'status'           => $pushStatus,
            'error_message'    => $errorMessage,
            'applied_at'       => $appliedAt,
        ]);

        $log->update(['status' => 'rolled_back']);

        $filters = $router->filterRules()->orderBy('priority')->get()->map(fn($r) => $r->toFrontend())->values();
        $nat     = $router->natRules()->orderBy('priority')->get()->map(fn($r) => $r->toFrontend())->values();

        return response()->json([
            'data' => [
                'routerId'  => (string) $router->id,
                'loadedAt'  => (int) ($appliedAt->timestamp * 1000),
                'appliedAt' => (int) ($appliedAt->timestamp * 1000),
                'filters'   => $filters,
                'nat'       => $nat,
            ],
        ]);
    }

    /**
     * GET /api/mikrotik/firewall/router-status?router_id=X
     * Comprueba si el router es alcanzable vía API RouterOS.
     */
    public function routerStatus(Request $request): JsonResponse
    {
        $request->validate([
            'router_id' => 'required|integer|exists:mikrotik_routers,id',
        ]);

        $router = MikrotikRouter::findOrFail($request->router_id);

        if (!$router->is_active) {
            return response()->json(['data' => ['reachable' => false, 'latency_ms' => null, 'reason' => 'Router marcado como inactivo']]);
        }

        $start   = microtime(true);
        $service = null;
        try {
            $client  = new Client([
                'host' => $router->host, 'user' => $router->username,
                'pass' => $router->password, 'port' => $router->port, 'timeout' => 10,
            ]);
            $service = new MikroTikService($client);
            $service->runQuery(new Query('/system/identity/print'));
            $latency = (int) round((microtime(true) - $start) * 1000);
            return response()->json(['data' => ['reachable' => true, 'latency_ms' => $latency]]);
        } catch (Throwable $e) {
            return response()->json(['data' => ['reachable' => false, 'latency_ms' => null, 'reason' => $e->getMessage()]]);
        } finally {
            if ($service) $service->disconnect();
            unset($service, $client);
        }
    }

    /**
     * POST /api/mikrotik/firewall/sync/merge-from-router
     * Importa solo las reglas del router que no existen en la BD (por external_id).
     */
    public function mergeFromRouter(Request $request): JsonResponse
    {
        $request->validate([
            'router_id' => 'required|integer|exists:mikrotik_routers,id',
        ]);

        $router = MikrotikRouter::findOrFail($request->router_id);

        $rawFilters = [];
        $rawNat     = [];
        $service    = null;
        try {
            $client  = new Client([
                'host' => $router->host, 'user' => $router->username,
                'pass' => $router->password, 'port' => $router->port, 'timeout' => 10,
            ]);
            $service    = new MikroTikService($client);
            $rawFilters = $service->getFirewallFilterRules();
            $rawNat     = $service->getFirewallNatRules();
        } catch (Throwable $e) {
            Log::error("FirewallController@mergeFromRouter connect failed: {$e->getMessage()}");
            return response()->json(['message' => 'No se pudo conectar al router: ' . $e->getMessage()], 502);
        } finally {
            if ($service) $service->disconnect();
            unset($service, $client);
        }

        // Firmas de contenido de las reglas ya en BD (immune a reasignación de IDs por RouterOS)
        $existingFilterSigs = $router->filterRules()->get()->map(fn($r) => implode('|', [
            $r->chain ?? '', $r->action ?? '', $r->protocol ?? 'any',
            $r->src_address ?? '', $r->dst_address ?? '',
            $r->src_port ?? '', $r->dst_port ?? '',
            $r->in_interface ?? '', $r->out_interface ?? '',
        ]))->toArray();

        $existingNatSigs = $router->natRules()->get()->map(fn($r) => implode('|', [
            $r->chain ?? '', $r->action ?? '', $r->protocol ?? 'any',
            $r->src_address ?? '', $r->dst_address ?? '',
            $r->src_port ?? '', $r->dst_port ?? '',
            $r->out_interface ?? '', $r->to_addresses ?? '', $r->to_ports ?? '',
        ]))->toArray();

        $added = 0;

        DB::transaction(function () use ($router, $rawFilters, $rawNat, $existingFilterSigs, $existingNatSigs, &$added) {
            $maxFilter = (int) $router->filterRules()->max('priority');
            $maxNat    = (int) $router->natRules()->max('priority');

            foreach ($rawFilters as $raw) {
                $chain    = $raw['chain']    ?? '';
                $action   = $raw['action']   ?? '';
                $protocol = $raw['protocol'] ?? 'any';

                if (!in_array($chain,    ['input', 'output', 'forward'],    true)) continue;
                if (!in_array($action,   ['accept', 'drop', 'reject'],       true)) continue;
                if (!in_array($protocol, ['any', 'tcp', 'udp', 'icmp'],     true)) $protocol = 'any';

                // Comparar por contenido, no por external_id (que cambia en cada apply)
                $sig = implode('|', [
                    $chain, $action, $protocol,
                    $raw['src-address']  ?? '', $raw['dst-address']  ?? '',
                    $raw['src-port']     ?? '', $raw['dst-port']     ?? '',
                    $raw['in-interface'] ?? '', $raw['out-interface'] ?? '',
                ]);
                if (in_array($sig, $existingFilterSigs, true)) continue;

                $router->filterRules()->create([
                    'uuid'             => Str::uuid(),
                    'external_id'      => $raw['.id']              ?? null,
                    'enabled'          => ($raw['disabled'] ?? 'false') !== 'true',
                    'priority'         => ++$maxFilter,
                    'chain'            => $chain,
                    'action'           => $action,
                    'protocol'         => $protocol,
                    'src_address'      => $raw['src-address']      ?? null,
                    'src_address_list' => $raw['src-address-list'] ?? null,
                    'dst_address'      => $raw['dst-address']      ?? null,
                    'src_port'         => $raw['src-port']         ?? null,
                    'dst_port'         => $raw['dst-port']         ?? null,
                    'in_interface'     => $raw['in-interface']     ?? null,
                    'out_interface'    => $raw['out-interface']    ?? null,
                    'comment'          => $raw['comment']          ?? null,
                    'log'              => ($raw['log'] ?? 'false') === 'yes',
                ]);
                $added++;
            }

            foreach ($rawNat as $raw) {
                $chain    = $raw['chain']    ?? '';
                $action   = $raw['action']   ?? '';
                $protocol = $raw['protocol'] ?? 'any';

                if (!in_array($chain,    ['srcnat', 'dstnat'],                              true)) continue;
                if (!in_array($action,   ['masquerade', 'src-nat', 'dst-nat', 'redirect'], true)) continue;
                if (!in_array($protocol, ['any', 'tcp', 'udp', 'icmp'],                    true)) $protocol = 'any';

                $sig = implode('|', [
                    $chain, $action, $protocol,
                    $raw['src-address']  ?? '', $raw['dst-address']  ?? '',
                    $raw['src-port']     ?? '', $raw['dst-port']     ?? '',
                    $raw['out-interface'] ?? '', $raw['to-addresses'] ?? '', $raw['to-ports'] ?? '',
                ]);
                if (in_array($sig, $existingNatSigs, true)) continue;

                $router->natRules()->create([
                    'uuid'             => Str::uuid(),
                    'external_id'      => $raw['.id']              ?? null,
                    'enabled'          => ($raw['disabled'] ?? 'false') !== 'true',
                    'priority'         => ++$maxNat,
                    'chain'            => $chain,
                    'action'           => $action,
                    'protocol'         => $protocol,
                    'src_address'      => $raw['src-address']      ?? null,
                    'src_address_list' => $raw['src-address-list'] ?? null,
                    'dst_address'      => $raw['dst-address']      ?? null,
                    'src_port'         => $raw['src-port']         ?? null,
                    'dst_port'         => $raw['dst-port']         ?? null,
                    'out_interface'    => $raw['out-interface']    ?? null,
                    'to_addresses'     => $raw['to-addresses']     ?? null,
                    'to_ports'         => $raw['to-ports']         ?? null,
                    'comment'          => $raw['comment']          ?? null,
                    'log'              => ($raw['log'] ?? 'false') === 'yes',
                ]);
                $added++;
            }

            $router->update(['last_loaded_at' => now()]);
        });

        $filters = $router->filterRules()->orderBy('priority')->get()->map(fn($r) => $r->toFrontend())->values();
        $nat     = $router->natRules()->orderBy('priority')->get()->map(fn($r) => $r->toFrontend())->values();
        $loadedAt = now();

        return response()->json([
            'message' => "Se importaron {$added} regla(s) nueva(s) desde el router.",
            'data'    => [
                'added'     => $added,
                'routerId'  => (string) $router->id,
                'loadedAt'  => (int) ($loadedAt->timestamp * 1000),
                'appliedAt' => $router->last_applied_at ? (int) ($router->last_applied_at->timestamp * 1000) : null,
                'filters'   => $filters,
                'nat'       => $nat,
            ],
        ]);
    }
}
