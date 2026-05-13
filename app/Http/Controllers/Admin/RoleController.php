<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::withCount('administradores')
            ->with('permissions:id,nombre,slug')
            ->orderBy('nombre')
            ->get()
            ->map(fn ($r) => [
                'id'                 => $r->id,
                'nombre'             => $r->nombre,
                'slug'               => $r->slug,
                'descripcion'        => $r->descripcion,
                'employees_count'    => $r->administradores_count,
                'permissions'        => $r->permissions->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->nombre, 'slug' => $p->slug]),
            ]);

        return response()->json(['data' => $roles]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre'       => 'required|string|max:100|unique:roles,nombre',
            'descripcion'  => 'nullable|string|max:500',
            'permissions'  => 'nullable|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $role = Role::create([
            'nombre'      => $validated['nombre'],
            'slug'        => Str::slug($validated['nombre']),
            'descripcion' => $validated['descripcion'] ?? null,
        ]);

        if (!empty($validated['permissions'])) {
            $role->permissions()->sync($validated['permissions']);
        }

        $role->load('permissions:id,nombre,slug');

        return response()->json([
            'message' => 'Rol creado exitosamente',
            'data'    => $role,
        ], 201);
    }

    public function show($id)
    {
        $role = Role::with('permissions:id,nombre,slug')
            ->withCount('administradores')
            ->find($id);

        if (!$role) {
            return response()->json(['message' => 'Rol no encontrado'], 404);
        }

        return response()->json([
            'data' => [
                'id'              => $role->id,
                'nombre'          => $role->nombre,
                'slug'            => $role->slug,
                'descripcion'     => $role->descripcion,
                'employees_count' => $role->administradores_count,
                'permissions'     => $role->permissions,
            ],
        ]);
    }

    public function update(Request $request, $id)
    {
        $role = Role::find($id);
        if (!$role) {
            return response()->json(['message' => 'Rol no encontrado'], 404);
        }

        $validated = $request->validate([
            'nombre'       => ['sometimes', 'required', 'string', 'max:100', \Illuminate\Validation\Rule::unique('roles', 'nombre')->ignore($role->id)],
            'descripcion'  => 'nullable|string|max:500',
            'permissions'  => 'nullable|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        if (isset($validated['nombre'])) {
            $role->nombre = $validated['nombre'];
            $role->slug   = \Illuminate\Support\Str::slug($validated['nombre']);
        }
        if (array_key_exists('descripcion', $validated)) {
            $role->descripcion = $validated['descripcion'];
        }
        $role->save();

        if (array_key_exists('permissions', $validated)) {
            $role->permissions()->sync($validated['permissions'] ?? []);
        }

        return response()->json(['message' => 'Rol actualizado exitosamente']);
    }

    public function destroy($id)
    {
        $role = Role::withCount('administradores')->find($id);
        if (!$role) {
            return response()->json(['message' => 'Rol no encontrado'], 404);
        }

        if ($role->administradores_count > 0) {
            return response()->json([
                'message' => "No se puede eliminar el rol porque tiene {$role->administradores_count} empleado(s) asignado(s).",
            ], 422);
        }

        $role->delete();
        return response()->json(['message' => 'Rol eliminado correctamente']);
    }

    public function permissions()
    {
        $permissions = Permission::orderBy('nombre')->get(['id', 'nombre', 'slug', 'descripcion']);
        return response()->json(['data' => $permissions]);
    }

    public function syncPermissions(Request $request, $id)
    {
        $role = Role::find($id);
        if (!$role) {
            return response()->json(['message' => 'Rol no encontrado'], 404);
        }

        $request->validate([
            'permissions'   => 'required|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $role->permissions()->sync($request->permissions);

        return response()->json(['message' => 'Permisos actualizados correctamente']);
    }
}
