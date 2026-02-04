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
    public function listSummary(Request $request)
    {
        $search = $request->input('search');
        $status = $request->input('status');
        $perPage = $request->input('per_page', 10);

        $query = Client::query();

        // Búsqueda
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('contact_phone', 'like', "%{$search}%");
            });
        }

        // Filtro de estado
        if ($status && $status !== 'all') {
            if ($status === 'active') {
                 $query->whereIn('service_status', ['active', 'ACTIVO', 'Active']);
            } elseif ($status === 'suspended') {
                 $query->whereIn('service_status', ['suspended', 'SUSPENDIDO', 'LIMITADO', 'Suspended']);
            } elseif ($status === 'inactive') {
                 $query->whereIn('service_status', ['inactive', 'INACTIVO', 'Inactive'])
                       ->orWhereNull('service_status');
            } else {
                 $query->where('service_status', $status);
            }
        }

        // Carga clientes y su plan activo más reciente (si existe)
        $paginator = $query->with(['clientPlans' => function ($q) {
                $q->where('status', 'active')
                  ->where(function ($qq) {
                      $qq->whereNull('end_date')
                         ->orWhere('end_date', '>=', now());
                  })
                  ->orderByDesc('start_date');
            }, 'clientPlans.plan'])
            ->select(['id', 'full_name', 'email', 'contact_phone', 'service_status'])
            ->paginate($perPage);

        // Transformar la colección dentro del paginador
        $paginator->getCollection()->transform(function ($client) {
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

        // Calcular estadísticas
        $stats = [
            'total' => Client::count(),
            'active' => Client::whereIn('service_status', ['active', 'ACTIVO', 'Active'])->count(),
            'suspended' => Client::whereIn('service_status', ['suspended', 'SUSPENDIDO', 'LIMITADO', 'Suspended'])->count(),
            'inactive' => Client::where(function($q) {
                 $q->whereIn('service_status', ['inactive', 'INACTIVO', 'Inactive'])
                   ->orWhereNull('service_status');
            })->count(),
        ];

        $response = $paginator->toArray();
        $response['stats'] = $stats;

        return response()->json($response);
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