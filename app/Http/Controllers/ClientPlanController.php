<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\ClientPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ClientPlanController extends Controller
{
    /**
     * Obtener todos los planes activos con sus características
     */
    public function getAllPlans(): JsonResponse
    {
        try {
            $plans = Plan::with(['features' => function($query) {
                $query->orderBy('order');
            }])
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('monthly_price')
            ->get();

            $formattedPlans = $plans->map(function($plan) {
                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'download_speed' => $plan->download_speed,
                    'upload_speed' => $plan->upload_speed,
                    'monthly_price' => (float) $plan->monthly_price,
                    'billing_cycle' => $plan->billing_cycle,
                    'category' => $plan->category,
                    'is_featured' => $plan->is_featured,
                    'features' => $plan->features->map(function($feature) {
                        return [
                            'id' => $feature->id,
                            'feature' => $feature->feature,
                            'icon' => $feature->icon,
                            'order' => $feature->order,
                            'highlighted' => $feature->highlighted
                        ];
                    })
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedPlans,
                'message' => 'Planes obtenidos exitosamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los planes: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener el plan actual del cliente
     */
       public function getCurrentClientPlan(Request $request): JsonResponse
    {
        try {
            // Obtener el usuario autenticado
            $client = Auth::user();
            
            if (!$client) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            // Asumiendo que el usuario tiene un client_id o está relacionado con un cliente
            // Dependiendo de tu estructura, puedes ajustar esto:
            
            // Opción 1: Si el usuario tiene directamente client_id
            $clientId = $client->id;
            
            // Opción 2: Si usas una relación belongsTo/hasOne
            // $clientId = $user->client->id;
            
            if (!$clientId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cliente no asociado a este usuario'
                ], 404);
            }

            $currentPlan = ClientPlan::with(['plan.features' => function($query) {
                $query->orderBy('order');
            }])
            ->where('client_id', $clientId)
            ->where('status', 'active')
            ->where(function($query) {
                $query->whereNull('end_date')
                      ->orWhere('end_date', '>=', now());
            })
            ->latest('start_date')
            ->first();

            if (!$currentPlan) {
                return response()->json([
                    'success' => true,
                    'data' => null,
                    'message' => 'El cliente no tiene un plan activo actualmente'
                ]);
            }

            $formattedPlan = [
                'id' => $currentPlan->id,
                'status' => $currentPlan->status,
                'start_date' => $currentPlan->start_date->format('Y-m-d'),
                'end_date' => $currentPlan->end_date ? $currentPlan->end_date->format('Y-m-d') : null,
                'next_billing_date' => $currentPlan->next_billing_date->format('Y-m-d'),
                'current_price' => (float) $currentPlan->current_price,
                'billing_cycle' => $currentPlan->billing_cycle,
                'ip_address' => $currentPlan->ip_address,
                'payment_method' => $currentPlan->payment_method,
                'mikrotik_queue_id' => $currentPlan->mikrotik_queue_id,
                'plan' => [
                    'id' => $currentPlan->plan->id,
                    'name' => $currentPlan->plan->name,
                    'slug' => $currentPlan->plan->slug,
                    'description' => $currentPlan->plan->description,
                    'download_speed' => $currentPlan->plan->download_speed,
                    'upload_speed' => $currentPlan->plan->upload_speed,
                    'symmetric' => $currentPlan->plan->symmetric,
                    'monthly_price' => (float) $currentPlan->plan->monthly_price,
                    'category' => $currentPlan->plan->category,
                    'is_featured' => $currentPlan->plan->is_featured,
                    'features' => $currentPlan->plan->features->map(function($feature) {
                        return [
                            'id' => $feature->id,
                            'feature' => $feature->feature,
                            'icon' => $feature->icon,
                            'highlighted' => $feature->highlighted
                        ];
                    })
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $formattedPlan,
                'message' => 'Plan actual obtenido exitosamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el plan actual: ' . $e->getMessage()
            ], 500);
        }
    }


    /* TRAEMOS SOLO LOS DATOS NECESARIOS PARAMOSTRAR EN FACTURAS */
    public function getCurrentClientPlanForInvoices(Request $request): JsonResponse
{
    try {
        // Obtener el usuario autenticado
        $client = Auth::user();
        
        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no autenticado'
            ], 401);
        }

        $clientId = $client->id;
        
        if (!$clientId) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente no asociado a este usuario'
            ], 404);
        }

        $currentPlan = ClientPlan::with('plan')
            ->where('client_id', $clientId)
            ->where('status', 'active')
            ->where(function($query) {
                $query->whereNull('end_date')
                      ->orWhere('end_date', '>=', now());
            })
            ->latest('start_date')
            ->first();

        if (!$currentPlan) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'El cliente no tiene un plan activo actualmente'
            ]);
        }

        // SOLO LOS DATOS QUE NECESITA EL FRONTEND
        $formattedPlan = [
            'current_price' => (float) $currentPlan->current_price,
            'billing_cycle' => $currentPlan->billing_cycle,
            'plan_name' => $currentPlan->plan->name,
            'next_billing_date' => $currentPlan->next_billing_date->format('Y-m-d')
        ];

        return response()->json([
            'success' => true,
            'data' => $formattedPlan,
            'message' => 'Plan actual obtenido exitosamente'
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener el plan actual: ' . $e->getMessage()
        ], 500);
    }
}
}