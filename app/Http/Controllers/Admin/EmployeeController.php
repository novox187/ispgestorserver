<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function profile(Request $request)
    {
        return $this->show($request->user()->id);
    }

    public function getRoles()
    {
        $roles = DB::table('roles')->select('id', 'nombre', 'slug')->get();
        return response()->json($roles);
    }

    public function listSummary(Request $request)
    {
        $employees = DB::table('employees')
            ->leftJoin('roles', 'employees.role_id', '=', 'roles.id')
            ->select(
                'employees.id',
                'employees.nombre as name',
                'employees.email',
                'employees.telefono as phone',
                'roles.nombre as role',
                DB::raw("'active' as status")
            )
            ->get();

        return response()->json($employees);
    }

    public function show($id)
    {
        $employee = Employee::with('role')->find($id);

        if (!$employee) {
            return response()->json(['message' => 'Empleado no encontrado'], 404);
        }

        $permissions = DB::table('role_permission')
            ->join('permissions', 'role_permission.fk_permission_id', '=', 'permissions.id')
            ->join('roles', 'role_permission.fk_role_id', '=', 'roles.id')
            ->where('roles.id', '=', $employee->role_id)
            ->pluck('permissions.nombre');

        return response()->json([
            'id' => $employee->id,
            'name' => $employee->nombre,
            'email' => $employee->email,
            'phone' => $employee->telefono,
            'role' => $employee->role ? $employee->role->nombre : null,
            'role_id' => $employee->role_id,
            'permissions' => $permissions
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:employees,email',
            'password' => 'required|min:6',
            'phone' => 'nullable|string|max:20',
            'role_id' => 'required|exists:roles,id'
        ]);

        $employee = new Employee();
        $employee->nombre = $validated['name'];
        $employee->email = $validated['email'];
        $employee->password = $validated['password']; // Mutator handles hashing
        $employee->telefono = $validated['phone'];
        $employee->role_id = $validated['role_id'];
        $employee->save();

        return response()->json(['message' => 'Empleado creado exitosamente', 'data' => $employee], 201);
    }

    public function update(Request $request, $id)
    {
        $employee = Employee::find($id);
        if (!$employee) {
            return response()->json(['message' => 'Empleado no encontrado'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', Rule::unique('employees')->ignore($employee->id)],
            'password' => 'nullable|min:6',
            'phone' => 'nullable|string|max:20',
            'role_id' => 'sometimes|required|exists:roles,id'
        ]);

        if (isset($validated['name'])) $employee->nombre = $validated['name'];
        if (isset($validated['email'])) $employee->email = $validated['email'];
        if (!empty($validated['password'])) $employee->password = $validated['password'];
        if (isset($validated['phone'])) $employee->telefono = $validated['phone'];
        if (isset($validated['role_id'])) $employee->role_id = $validated['role_id'];
        
        $employee->save();

        return response()->json(['message' => 'Empleado actualizado exitosamente', 'data' => $employee]);
    }

    public function destroy($id)
    {
        $employee = Employee::find($id);
        if (!$employee) {
            return response()->json(['message' => 'Empleado no encontrado'], 404);
        }

        $employee->delete();
        return response()->json(['message' => 'Empleado eliminado exitosamente']);
    }
}