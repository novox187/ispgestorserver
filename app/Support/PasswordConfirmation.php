<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Exige que quien pide una acción destructiva vuelva a teclear su contraseña.
 *
 * ## Por qué no basta con un modal en el panel
 *
 * Un aviso en la interfaz frena el clic accidental, pero no protege de nada
 * más: la API sigue aceptando la petición sin más. Si a alguien le roban la
 * sesión —o deja el portátil abierto— revocar un agente es una llamada HTTP.
 * La confirmación tiene que valer aquí, en el servidor, o no vale.
 *
 * ## Por qué está limitada por intentos
 *
 * Sin límite, este endpoint es un oráculo de contraseñas: se puede probar una
 * lista contra él con la sesión ya iniciada y averiguar la clave del operador,
 * que probablemente reutiliza en otros sitios. El contador va por usuario y no
 * por IP para que cambiar de red no lo reinicie.
 */
final class PasswordConfirmation
{
    /** Intentos fallidos antes de bloquear. */
    private const MAX_ATTEMPTS = 5;

    /** Segundos que dura el bloqueo tras agotarlos. */
    private const LOCKOUT = 300;

    /**
     * Devuelve null si la contraseña es correcta, o la respuesta de error.
     */
    public static function check(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return self::error('PASSWORD_CONFIRMATION_REQUIRED', 'No hay sesión con la que confirmar.', 401);
        }

        $clave = self::rateLimitKey($user->getAuthIdentifier());

        if (RateLimiter::tooManyAttempts($clave, self::MAX_ATTEMPTS)) {
            $segundos = RateLimiter::availableIn($clave);

            return self::error(
                'PASSWORD_CONFIRMATION_LOCKED',
                "Demasiados intentos fallidos. Vuelve a probar en {$segundos} segundos.",
                429,
            );
        }

        $password = $request->input('password');

        if (!is_string($password) || $password === '') {
            return self::error(
                'PASSWORD_CONFIRMATION_REQUIRED',
                'Esta acción exige confirmar tu contraseña.',
            );
        }

        if (!Hash::check($password, (string) $user->getAuthPassword())) {
            // Solo cuentan los intentos con contraseña equivocada: pedir la
            // confirmación y cancelarla no debería acercar a nadie al bloqueo.
            RateLimiter::hit($clave, self::LOCKOUT);

            return self::error(
                'PASSWORD_CONFIRMATION_INVALID',
                'La contraseña no es correcta.',
            );
        }

        RateLimiter::clear($clave);

        return null;
    }

    private static function rateLimitKey(mixed $userId): string
    {
        return 'confirmar-clave:' . $userId;
    }

    private static function error(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
