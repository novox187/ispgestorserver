<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MikrotikRouter;
use Illuminate\Http\JsonResponse;

/**
 * Expone el estado de "primeros pasos" requeridos antes de operar el sistema.
 *
 * El frontend consulta este endpoint al iniciar sesión y al re-entrar al panel.
 * Si `primary_router_configured=false`, se muestra un banner persistente con
 * CTA al formulario de creación, y se deshabilitan los módulos dependientes
 * (firewall, sync de colas, monitoreo).
 */
class SystemBootstrapController extends Controller
{
    public function status(): JsonResponse
    {
        $router = MikrotikRouter::primaryRouter();

        return response()->json([
            'primary_router_configured' => $router !== null,
            'primary_router'            => $router ? [
                'id'                   => $router->id,
                'name'                 => $router->name,
                'host'                 => $router->host,
                'port'                 => $router->port,
                'is_active'            => $router->is_active,
                'connectivity_status'  => $router->connectivity_status,
                'last_health_check_at' => $router->last_health_check_at?->toIso8601String(),
            ] : null,
            'routers_total' => MikrotikRouter::count(),
            'cta'           => [
                'message'     => 'Configure el router principal para habilitar todas las funcionalidades.',
                'redirect_to' => '/red/dispositivos',
                'label'       => 'Configurar router principal',
            ],
        ]);
    }
}
