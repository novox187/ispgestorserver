<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MikrotikRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MikrotikRouterController extends Controller
{
    public function index(): JsonResponse
    {
        $routers = MikrotikRouter::orderBy('name')
            ->get()
            ->map(fn($r) => $this->mapRouter($r));

        return response()->json(['data' => $routers]);
    }

    public function show(int $id): JsonResponse
    {
        $router = MikrotikRouter::findOrFail($id);

        return response()->json(['data' => $this->mapRouter($router)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'host'        => 'required|string|max:255',
            'port'        => 'nullable|integer|min:1|max:65535',
            'username'    => 'required|string|max:100',
            'password'    => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'is_active'   => 'nullable|boolean',
        ]);

        $router = MikrotikRouter::create($validated);

        return response()->json(['data' => $this->mapRouter($router)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $router = MikrotikRouter::findOrFail($id);

        $validated = $request->validate([
            'name'        => 'sometimes|required|string|max:100',
            'host'        => 'sometimes|required|string|max:255',
            'port'        => 'nullable|integer|min:1|max:65535',
            'username'    => 'sometimes|required|string|max:100',
            'password'    => 'nullable|string|max:255',
            'description' => 'nullable|string|max:500',
            'is_active'   => 'nullable|boolean',
        ]);

        // No pisar la contraseña si no se envió
        if (empty($validated['password'])) {
            unset($validated['password']);
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

    private function mapRouter(MikrotikRouter $router): array
    {
        return [
            'id'            => $router->id,
            'name'          => $router->name,
            'host'          => $router->host,
            'port'          => $router->port,
            'username'      => $router->username,
            'description'   => $router->description,
            'is_active'     => $router->is_active,
            'last_loaded_at'  => $router->last_loaded_at  ? (int) ($router->last_loaded_at->timestamp  * 1000) : null,
            'last_applied_at' => $router->last_applied_at ? (int) ($router->last_applied_at->timestamp * 1000) : null,
            'created_at'    => $router->created_at ? (int) ($router->created_at->timestamp * 1000) : null,
        ];
    }
}
