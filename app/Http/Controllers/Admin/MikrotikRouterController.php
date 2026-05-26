<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MikrotikRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MikrotikRouterController extends Controller
{
    public function index(): JsonResponse
    {
        $routers = MikrotikRouter::orderByDesc('is_primary')
            ->orderBy('name')
            ->get()
            ->map(fn ($r) => $this->mapRouter($r));

        return response()->json(['data' => $routers]);
    }

    public function show(int $id): JsonResponse
    {
        $router = MikrotikRouter::findOrFail($id);

        return response()->json(['data' => $this->mapRouter($router)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $router = MikrotikRouter::create($validated);

        return response()->json(['data' => $this->mapRouter($router)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $router = MikrotikRouter::findOrFail($id);

        $validated = $request->validate($this->rules(forUpdate: true));

        // No pisar la contraseña si no se envió.
        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        // No permitir des-marcar el primary sin marcar otro: si solo hay uno
        // y el admin envía is_primary=false, lo ignoramos para no dejar al
        // sistema sin router por defecto. La forma correcta de "cambiar" el
        // primary es marcar otro como tal.
        if (
            array_key_exists('is_primary', $validated)
            && $validated['is_primary'] === false
            && $router->is_primary
            && MikrotikRouter::count() === 1
        ) {
            unset($validated['is_primary']);
        }

        $router->update($validated);

        return response()->json(['data' => $this->mapRouter($router->fresh())]);
    }

    public function destroy(int $id): JsonResponse
    {
        $router = MikrotikRouter::findOrFail($id);
        $router->delete();

        return response()->json(['data' => null], 204);
    }

    /**
     * @return array<string, array<int,mixed>|string>
     */
    private function rules(bool $forUpdate = false): array
    {
        $required = $forUpdate ? 'sometimes' : 'required';

        return [
            'name'         => [$required, 'string', 'max:100'],
            'host'         => [$required, 'string', 'max:255'],
            'port'         => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username'     => [$required, 'string', 'max:100'],
            'password'     => [$forUpdate ? 'nullable' : 'required', 'string', 'max:255'],
            'description'  => ['nullable', 'string', 'max:500'],
            'is_active'    => ['nullable', 'boolean'],
            'is_primary'   => ['nullable', 'boolean'],
            // CIDR del rango de clientes asignados al router.
            // Ej: 192.168.20.0/24 — sirve para inferir la subred a partir del gateway.
            'network_cidr' => ['nullable', 'string', 'max:45', 'regex:/^\d{1,3}(\.\d{1,3}){3}\/\d{1,2}$/'],
            'gateway'      => ['nullable', 'ip', 'max:45'],
        ];
    }

    private function mapRouter(MikrotikRouter $router): array
    {
        return [
            'id'                   => $router->id,
            'name'                 => $router->name,
            'host'                 => $router->host,
            'port'                 => $router->port,
            'username'             => $router->username,
            'description'          => $router->description,
            'is_active'            => $router->is_active,
            'is_primary'           => $router->is_primary,
            'network_cidr'         => $router->network_cidr,
            'gateway'              => $router->gateway,
            'connectivity_status'  => $router->connectivity_status,
            'last_health_check_at' => $router->last_health_check_at ? (int) ($router->last_health_check_at->timestamp * 1000) : null,
            'last_connected_at'    => $router->last_connected_at    ? (int) ($router->last_connected_at->timestamp    * 1000) : null,
            'last_disconnected_at' => $router->last_disconnected_at ? (int) ($router->last_disconnected_at->timestamp * 1000) : null,
            'consecutive_failures' => $router->consecutive_failures,
            'last_loaded_at'       => $router->last_loaded_at       ? (int) ($router->last_loaded_at->timestamp       * 1000) : null,
            'last_applied_at'      => $router->last_applied_at      ? (int) ($router->last_applied_at->timestamp      * 1000) : null,
            'created_at'           => $router->created_at           ? (int) ($router->created_at->timestamp           * 1000) : null,
        ];
    }
}
