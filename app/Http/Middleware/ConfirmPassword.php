<?php

namespace App\Http\Middleware;

use App\Support\PasswordConfirmation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege una ruta exigiendo que se reenvíe la contraseña del operador.
 *
 * Se aplica a las acciones que rompen algo y cuesta deshacer: revocar o
 * eliminar un agente obliga a volver a instalarlo en la máquina donde vive, y
 * eso puede significar un viaje a la oficina del cliente.
 *
 * La comprobación vive en `PasswordConfirmation` y no aquí porque hay casos
 * —desactivar un agente, que se hace en el mismo endpoint que renombrarlo— en
 * los que solo se exige según lo que traiga el cuerpo, y ahí no sirve un
 * middleware de ruta.
 */
class ConfirmPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        return PasswordConfirmation::check($request) ?? $next($request);
    }
}
