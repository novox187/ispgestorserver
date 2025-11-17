<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthClientController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Verificar si el cliente existe y está activo
        $cliente = Client::where('email', $credentials['email'])->first();

        if (!$cliente) {
            throw ValidationException::withMessages([
                'email' => ['Credenciales inválidas.'],
            ]);
        }

        // Verificar si el cliente está activo (si tienes una columna 'estatus')
        if (isset($cliente->estatus) && $cliente->estatus !== 'ACTIVO') {
            throw ValidationException::withMessages([
                'email' => ['Tu cuenta está inactiva. Contacta al administrador.'],
            ]);
        }

        // Intentar autenticación
        if (!Auth::guard('client')->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciales inválidas.'],
            ]);
        }

        // Obtener al cliente autenticado (ya no necesitas buscarlo de nuevo)
        $clienteAutenticado = Auth::guard('client')->user();
        
        // Crear token Sanctum
        $token = $clienteAutenticado->createToken('client-api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'cliente' => [
                'id_cliente' => $clienteAutenticado->id,
                'email' => $clienteAutenticado->email,
                'nombre_completo' => $clienteAutenticado->full_name, // Asegúrate de que esta propiedad exista
            ],
        ]);
    }

    /**
     * Cerrar sesión del cliente actual invalidando su token de acceso actual (Sanctum).
     */
    public function logout(Request $request)
    {
        // Usar el guard 'client' para obtener el usuario autenticado
        $cliente = Auth::guard('client')->user();

        if ($cliente && method_exists($cliente, 'currentAccessToken')) {
            $token = $cliente->currentAccessToken();
            if ($token) {
                $token->delete();
            }
        }

        // También cerrar sesión en el guard
        Auth::guard('client')->logout();

        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }

    /**
     * Obtener el perfil del cliente autenticado
     */
    public function profile(Request $request)
    {
        $cliente = Auth::guard('client')->user();
        
        return response()->json([
            'cliente' => [
                'id_cliente' => $cliente->id,
                'email' => $cliente->email,
                'nombre_completo' => $cliente->full_name,
                // ... otros campos que quieras devolver
            ]
        ]);
    }
}