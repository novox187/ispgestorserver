<?php

namespace App\Http\Middleware;

use App\Models\MikrotikRouter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea rutas que dependen de un router MikroTik configurado.
 *
 * Si la tabla `network_devices` no tiene ningún registro marcado como
 * `is_primary=true`, devuelve **423 Locked** con un payload que el frontend
 * usa para redirigir al admin al formulario de creación.
 *
 * Aplicar a rutas del sistema que necesitan conexión RouterOS para operar:
 * firewall, sincronización de colas, monitoreo, suspensiones automáticas.
 *
 * NO aplicar a: dispositivos (CRUD del router), clientes, facturación,
 * dashboard, configuraciones — todo eso sigue operando aunque no haya router.
 */
class EnsurePrimaryRouter
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!MikrotikRouter::hasPrimary()) {
            return response()->json([
                'message'              => 'No hay un router MikroTik principal configurado. '
                                          . 'Registre el primer dispositivo antes de usar esta funcionalidad.',
                'code'                 => 'no_primary_router',
                'redirect_to'          => '/red/dispositivos',
                'cta_label'            => 'Configurar router principal',
            ], 423);
        }

        return $next($request);
    }
}
