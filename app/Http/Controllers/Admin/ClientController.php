<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Client;

class ClientController extends Controller
{
   
   /**
     * Listado resumido de clientes: Nombre, Email, Teléfono, Plan actual, Estado del servicio
     */
    public function listSummary()
    {
        // Carga clientes y su plan activo más reciente (si existe)
        $clients = Client::query()
            ->with(['clientPlans' => function ($q) {
                $q->where('status', 'active')
                  ->where(function ($qq) {
                      $qq->whereNull('end_date')
                         ->orWhere('end_date', '>=', now());
                  })
                  ->orderByDesc('start_date');
            }, 'clientPlans.plan'])
            ->select(['id', 'full_name', 'email', 'contact_phone', 'service_status'])
            ->get()
            ->map(function ($client) {
                $currentPlan = $client->clientPlans->first();
                return [
                    'id' => $client->id,
                    'name' => $client->full_name,
                    'email' => $client->email,
                    'phone' => $client->contact_phone,
                    'plan' => $currentPlan && $currentPlan->plan ? $currentPlan->plan->name : null,
                    'status' => $client->service_status,
                ];
            });

        return response()->json($clients);
    }

    /**
     * Listado completo de clientes con todas sus relaciones principales
     */
    public function listFull()
    {
        $clients = Client::query()
            ->with([
                // Planes del cliente y el detalle del plan
                'clientPlans.plan',
            ])
            ->get();

        return response()->json($clients);
    }

    /**
     * Mostrar la información completa de un solo cliente por ID
     */
    public function showFull($id)
    {
        $client = Client::query()
            ->with([
                // Solo el plan activo vigente del cliente y su detalle
                'clientPlans' => function ($q) {
                    $q->where('status', 'active')
                      ->where(function ($qq) {
                          $qq->whereNull('end_date')
                             ->orWhere('end_date', '>=', now());
                      })
                      ->orderByDesc('start_date');
                },
                'clientPlans.plan',
                // 'servicios', // Relación deshabilitada: modelo no disponible
                'soportes',
            ])
            ->findOrFail($id);

        return response()->json($client);
    }
}