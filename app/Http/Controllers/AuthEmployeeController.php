<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthEmployeeController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $employee = Employee::where('email', $credentials['email'])->first();
        if (!$employee) {
            Log::warning('Login empleado: usuario no encontrado', [
                'email' => $credentials['email'],
                'ip' => $request->ip(),
            ]);
            throw ValidationException::withMessages([
                'email' => ['Credenciales inválidas.'],
            ]);
        }

        if (!Auth::guard('employee')->attempt($credentials)) {
            Log::warning('Login empleado: credenciales inválidas', [
                'email' => $credentials['email'],
                'ip' => $request->ip(),
            ]);
            throw ValidationException::withMessages([
                'email' => ['Credenciales inválidas.'],
            ]);
        }

        $authEmployee = Auth::guard('employee')->user();
        
        if (!$authEmployee) {
            Log::error('Login empleado: guard retornó null tras attempt', [
                'email' => $credentials['email'],
                'ip' => $request->ip(),
            ]);
            throw ValidationException::withMessages([
                'email' => ['No se pudo autenticar al empleado.'],
            ]);
        }

        // Cargar relación de rol
        $authEmployee->load('role');

        $token = $authEmployee->createToken('employee-api')->plainTextToken;
        Log::info('Login empleado: éxito', [
            'employee_id' => $authEmployee->id,
            'email' => $authEmployee->email,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'token' => $token,
            'role' => 'employee',
            'employee' => [
                'id' => $authEmployee->id,
                'email' => $authEmployee->email,
                'nombre' => $authEmployee->nombre,
                'role' => $authEmployee->role ? $authEmployee->role->nombre : 'Sin Rol',
                'role_slug' => $authEmployee->role ? $authEmployee->role->slug : null,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $employee = Auth::guard('employee')->user();
        if ($employee && method_exists($employee, 'currentAccessToken')) {
            $token = $employee->currentAccessToken();
            if ($token) {
                $token->delete();
            }
        }
        Auth::guard('employee')->logout();
        Log::info('Logout empleado: éxito', [
            'employee_id' => $employee?->id,
            'email' => $employee?->email,
            'ip' => $request->ip(),
        ]);
        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }
}
