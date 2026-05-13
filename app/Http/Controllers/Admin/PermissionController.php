<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PermissionController extends Controller
{
    public function index()
    {
        $permissions = Permission::withCount('roles')
            ->orderBy('nombre')
            ->get()
            ->map(fn ($p) => [
                'id'          => $p->id,
                'nombre'      => $p->nombre,
                'slug'        => $p->slug,
                'descripcion' => $p->descripcion,
                'roles_count' => $p->roles_count,
            ]);

        return response()->json(['data' => $permissions]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre'      => 'required|string|max:100|unique:permissions,nombre',
            'descripcion' => 'nullable|string|max:500',
        ]);

        $slug = Str::slug(str_replace(' ', '_', $validated['nombre']), '_');

        if (Permission::where('slug', $slug)->exists()) {
            return response()->json([
                'message' => 'Ya existe un permiso con ese slug.',
                'errors'  => ['nombre' => ['El nombre genera un slug duplicado: ' . $slug]],
            ], 422);
        }

        $permission = Permission::create([
            'nombre'      => $validated['nombre'],
            'slug'        => $slug,
            'descripcion' => $validated['descripcion'] ?? null,
        ]);

        return response()->json([
            'message' => 'Permiso creado exitosamente',
            'data'    => $permission,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $permission = Permission::find($id);
        if (!$permission) {
            return response()->json(['message' => 'Permiso no encontrado'], 404);
        }

        $validated = $request->validate([
            'nombre'      => ['sometimes', 'required', 'string', 'max:100', Rule::unique('permissions', 'nombre')->ignore($permission->id)],
            'descripcion' => 'nullable|string|max:500',
        ]);

        if (isset($validated['nombre'])) {
            $permission->nombre = $validated['nombre'];
            $permission->slug   = Str::slug(str_replace(' ', '_', $validated['nombre']), '_');
        }
        if (array_key_exists('descripcion', $validated)) {
            $permission->descripcion = $validated['descripcion'];
        }
        $permission->save();

        return response()->json(['message' => 'Permiso actualizado exitosamente']);
    }

    public function destroy($id)
    {
        $permission = Permission::withCount('roles')->find($id);
        if (!$permission) {
            return response()->json(['message' => 'Permiso no encontrado'], 404);
        }

        if ($permission->roles_count > 0) {
            return response()->json([
                'message' => "No se puede eliminar: este permiso está asignado a {$permission->roles_count} rol(es).",
            ], 422);
        }

        $permission->delete();
        return response()->json(['message' => 'Permiso eliminado correctamente']);
    }
}
