<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function profile(Request $request)
    {
        return $this->show($request->user()->id);
    }

    public function getRoles()
    {
        $roles = DB::table('roles')->select('id', 'nombre', 'slug', 'descripcion')->get();
        return response()->json(['data' => $roles]);
    }

    public function index(Request $request)
    {
        $filters = $request->only(['search', 'status', 'role_id', 'date_from', 'date_to', 'trashed']);

        $sortBy    = in_array($request->input('sort_by'), ['nombre', 'email', 'created_at', 'status']) ? $request->input('sort_by') : 'created_at';
        $sortDir   = $request->input('sort_dir') === 'asc' ? 'asc' : 'desc';
        $perPage   = min((int) $request->input('per_page', 15), 100);

        $query = Employee::with('role')
            ->filter($filters)
            ->orderBy($sortBy, $sortDir);

        $paginator = $query->paginate($perPage);

        $items = $paginator->getCollection()->map(fn ($e) => [
            'id'         => $e->id,
            'name'       => $e->nombre,
            'email'      => $e->email,
            'phone'      => $e->telefono,
            'role'       => $e->role?->nombre,
            'role_id'    => $e->role_id,
            'status'     => $e->status,
            'created_at' => $e->created_at?->toISOString(),
            'deleted_at' => $e->deleted_at?->toISOString(),
        ]);

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'from'         => $paginator->firstItem(),
                'to'           => $paginator->lastItem(),
            ],
        ]);
    }

    // Legacy endpoint — kept for cache compatibility
    public function listSummary(Request $request)
    {
        return $this->index($request);
    }

    public function show($id)
    {
        $employee = Employee::withTrashed()->with('role.permissions')->find($id);

        if (!$employee) {
            return response()->json(['message' => 'Empleado no encontrado'], 404);
        }

        $permissions = $employee->role?->permissions->pluck('nombre') ?? collect();

        return response()->json([
            'data' => [
                'id'          => $employee->id,
                'name'        => $employee->nombre,
                'email'       => $employee->email,
                'phone'       => $employee->telefono,
                'role'        => $employee->role?->nombre,
                'role_id'     => $employee->role_id,
                'role_slug'   => $employee->role?->slug,
                'status'      => $employee->status,
                'permissions' => $permissions,
                'created_at'  => $employee->created_at?->toISOString(),
                'deleted_at'  => $employee->deleted_at?->toISOString(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:employees,email',
            'password' => 'required|min:8',
            'phone'    => 'nullable|string|max:20',
            'role_id'  => 'required|exists:roles,id',
            'status'   => 'sometimes|in:active,inactive',
        ]);

        $employee           = new Employee();
        $employee->nombre   = $validated['name'];
        $employee->email    = $validated['email'];
        $employee->password = $validated['password'];
        $employee->telefono = $validated['phone'] ?? null;
        $employee->role_id  = $validated['role_id'];
        $employee->status   = $validated['status'] ?? 'active';
        $employee->save();

        return response()->json([
            'message' => 'Empleado creado exitosamente',
            'data'    => ['id' => $employee->id, 'name' => $employee->nombre, 'email' => $employee->email],
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $employee = Employee::find($id);
        if (!$employee) {
            return response()->json(['message' => 'Empleado no encontrado'], 404);
        }

        $validated = $request->validate([
            'name'     => 'sometimes|required|string|max:255',
            'email'    => ['sometimes', 'required', 'email', Rule::unique('employees')->ignore($employee->id)],
            'password' => 'nullable|min:8',
            'phone'    => 'nullable|string|max:20',
            'role_id'  => 'sometimes|required|exists:roles,id',
            'status'   => 'sometimes|in:active,inactive',
        ]);

        if (isset($validated['name']))     $employee->nombre   = $validated['name'];
        if (isset($validated['email']))    $employee->email    = $validated['email'];
        if (!empty($validated['password'])) $employee->password = $validated['password'];
        if (array_key_exists('phone', $validated)) $employee->telefono = $validated['phone'];
        if (isset($validated['role_id']))  $employee->role_id  = $validated['role_id'];
        if (isset($validated['status']))   $employee->status   = $validated['status'];

        $employee->save();

        return response()->json(['message' => 'Empleado actualizado exitosamente']);
    }

    public function destroy($id)
    {
        $employee = Employee::find($id);
        if (!$employee) {
            return response()->json(['message' => 'Empleado no encontrado'], 404);
        }

        $employee->delete();
        return response()->json(['message' => 'Empleado eliminado correctamente']);
    }

    public function restore($id)
    {
        $employee = Employee::withTrashed()->find($id);
        if (!$employee) {
            return response()->json(['message' => 'Empleado no encontrado'], 404);
        }

        if (!$employee->trashed()) {
            return response()->json(['message' => 'El empleado no está eliminado'], 422);
        }

        $employee->restore();
        return response()->json(['message' => 'Empleado restaurado correctamente']);
    }

    public function forceDelete($id)
    {
        $employee = Employee::withTrashed()->find($id);
        if (!$employee) {
            return response()->json(['message' => 'Empleado no encontrado'], 404);
        }

        $employee->forceDelete();
        return response()->json(['message' => 'Empleado eliminado permanentemente']);
    }

    public function toggleStatus($id)
    {
        $employee = Employee::find($id);
        if (!$employee) {
            return response()->json(['message' => 'Empleado no encontrado'], 404);
        }

        $employee->status = $employee->status === 'active' ? 'inactive' : 'active';
        $employee->save();

        return response()->json([
            'message' => 'Estado actualizado',
            'data'    => ['status' => $employee->status],
        ]);
    }
}
