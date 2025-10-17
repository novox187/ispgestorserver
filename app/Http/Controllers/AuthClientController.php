<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
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

        if (! Auth::guard('client')->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciales inválidas.'],
            ]);
        }

        // Obtener al cliente autenticado y emitir token Sanctum
        $cliente = Cliente::where('email', $credentials['email'])->firstOrFail();
        $token = $cliente->createToken('client-api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'cliente' => [
                'id_cliente' => $cliente->id_cliente,
                'email' => $cliente->email,
                'nombre_completo' => $cliente->nombre_completo,
            ],
        ]);
    }

    /**
     * Cerrar sesión del cliente actual invalidando su token de acceso actual (Sanctum).
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        // Si se autentica por token de Sanctum, eliminar solo el token actual
        if ($user && method_exists($user, 'currentAccessToken')) {
            $token = $user->currentAccessToken();
            if ($token) {
                $token->delete();
            }
        }

        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }
}
