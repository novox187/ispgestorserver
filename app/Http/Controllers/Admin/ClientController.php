<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Audit;
use App\Services\MikroTikService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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
            } elseif ($status === 'without_plan') {
                 // Filtrar clientes que NO tienen un plan activo vigente
                 $query->whereDoesntHave('clientPlans', function ($q) {
                     $q->where('status', 'active')
                       ->where(function ($qq) {
                           $qq->whereNull('end_date')
                              ->orWhere('end_date', '>=', now());
                       });
                 });
            } else {
                 $query->where('service_status', $status);
            }
        }

        // Ordenar: Clientes 'cancelled' al final
        $query->orderByRaw("CASE WHEN service_status = 'cancelled' THEN 1 ELSE 0 END")
              ->orderBy('id');

        // Carga clientes y su plan activo más reciente (si existe)
        $paginator = $query->with(['clientPlans' => function ($q) {
                $q->where('status', 'active')
                  ->where(function ($qq) {
                      $qq->whereNull('end_date')
                         ->orWhere('end_date', '>=', now());
                  })
                  ->orderByDesc('start_date');
            }, 'clientPlans.plan'])
            ->select(['id', 'full_name', 'email', 'contact_phone', 'service_status','document_id'])
            ->paginate($perPage);

        // Transformar la colección dentro del paginador
        $paginator->getCollection()->transform(function ($client) {
            $currentPlan = $client->clientPlans->first();
            return [
                'id' => $client->id,
                'name' => $client->full_name,
                'document_id' => $client->document_id, // Agregado para poder reenviarlo en actualizaciones
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
                'wallet:id,client_id,balance',
                // 'servicios', // Relación deshabilitada: modelo no disponible
                'soportes',
                'invoices' => function ($q) {
                    $q->orderByDesc('issue_date');
                }
            ])
            ->findOrFail($id);

        $client->wallet_balance = $client->balance;

        return response()->json($client);
    }

    /**
     * Actualizar cliente: Datos básicos, plan y registro de auditoría con motivo
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'full_name' => 'required|string|max:255',
            'document_id' => 'required|string|max:50|unique:clients,document_id,' . $id, // Ignorar ID actual
            'email' => 'required|email|max:255',
            'reason' => 'required|string|min:5',
        ]);

        $client = Client::findOrFail($id);
        
        DB::beginTransaction();
        try {
            $oldValues = $client->toArray();
            
            // 1. Actualizar datos básicos
            $client->fill($request->except(['reason', 'plan_id', 'plan']));
            
            $changes = [];
            if ($client->isDirty()) {
                $changes = $client->getDirty();
                // Desactivamos eventos para evitar doble log si usamos Auditable, 
                // ya que haremos un log manual más rico con el 'reason'
                $client->unsetEventDispatcher(); 
                $client->save();
            }

            // 2. Gestión de cambio de plan (básico en DB)
            $planChanged = false;
            $oldPlanName = 'N/A';
            $newPlanName = 'N/A';

            if ($request->has('plan_id')) {
                 $newPlanId = $request->plan_id;
                 // Obtener plan actual activo
                 $currentPlan = $client->clientPlans()
                        ->where('status', 'active')
                        ->where(function ($q) {
                            $q->whereNull('end_date')->orWhere('end_date', '>=', now());
                        })
                        ->orderByDesc('start_date')
                        ->first();
                 
                 $currentPlanId = $currentPlan ? $currentPlan->plan_id : null;
                 $oldPlanName = $currentPlan && $currentPlan->plan ? $currentPlan->plan->name : 'Ninguno';

                 // Si el plan es diferente (o no tenía plan y ahora sí)
                 if ($newPlanId != $currentPlanId) {
                     // Cerrar plan anterior
                     if ($currentPlan) {
                         $currentPlan->update(['end_date' => now(), 'status' => 'inactive']);
                     }
                     
                     // Crear nuevo plan si se seleccionó uno válido
                     if ($newPlanId) {
                         $newPlan = Plan::find($newPlanId);
                         $price = $newPlan ? $newPlan->monthly_price : 0;
                         
                         $client->clientPlans()->create([
                             'plan_id' => $newPlanId,
                             'start_date' => now(),
                             'next_billing_date' => now()->addMonth(),
                             'current_price' => $price,
                             'status' => 'active',
                             'ip_address' => $client->ip // Guardar la IP del cliente en la relación
                         ]);
                         $newPlanName = $newPlan ? $newPlan->name : "ID: $newPlanId";
                     }
                     $planChanged = true;
                 }
            }

            // 3. Auditoría Manual con Motivo
            Audit::create([
                'table_name' => 'clients',
                'operation' => 'UPDATE_DETAILS',
                'record_id' => (string) $client->id,
                'old_values' => array_merge($oldValues, ['plan_name' => $oldPlanName]),
                'new_values' => array_merge($client->toArray(), [
                    'reason' => $request->reason,
                    'plan_changed' => $planChanged,
                    'new_plan_name' => $newPlanName
                ]),
                'user_id' => Auth::id(),
                'ip_address' => $request->ip(),
            ]);

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Cliente actualizado correctamente', 'client' => $client]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error actualizando cliente ID {$id}: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error actualizando cliente: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Suspender cliente: Actualiza DB, bloquea en Mikrotik y registra auditoría
     */
    public function suspend(Request $request, $id, MikroTikService $mikrotik)
    {
        Log::info("Iniciando proceso de suspensión para cliente ID: {$id}", ['user' => Auth::id()]);
        
        try {
            $client = Client::findOrFail($id);
        } catch (\Exception $e) {
             Log::error("Cliente no encontrado para suspensión: {$id}");
             return response()->json(['success' => false, 'message' => 'Cliente no encontrado'], 404);
        }

        // Validaciones previas
        if (in_array(strtoupper($client->service_status), ['SUSPENDIDO', 'SUSPENDED'])) {
            Log::warning("Intento de suspender cliente ya suspendido: {$id}");
            return response()->json(['success' => false, 'message' => 'El cliente ya está suspendido'], 400);
        }

        if (!$client->ip) {
             Log::warning("Intento de suspender cliente sin IP: {$id}");
             return response()->json(['success' => false, 'message' => 'El cliente no tiene IP asignada para bloquear'], 400);
        }

        // Validar conexión Mikrotik
        try {
            Log::info("Verificando conexión con Mikrotik...");
            $sysInfo = $mikrotik->getSystemInfo();
            if (empty($sysInfo)) {
                throw new \Exception('No hay conexión con el router MikroTik (Respuesta vacía)');
            }
            Log::info("Conexión Mikrotik OK.");
        } catch (\Exception $e) {
             Log::error("Error de conexión Mikrotik previo a suspensión: " . $e->getMessage());
             return response()->json(['success' => false, 'message' => 'Error de conectividad Mikrotik: ' . $e->getMessage()], 503);
        }

        DB::beginTransaction();
        Log::info("Transacción DB iniciada.");

        try {
            $oldStatus = $client->service_status;
            
            // 1. Intentar bloquear en Mikrotik (Address List "morosos")
            Log::info("Enviando comando a Mikrotik para agregar IP {$client->ip} a address-list 'morosos'");
            
            $mkResult = $mikrotik->addIpToAddressList(
                $client->ip, 
                'morosos', 
                "Suspendido por falta de pago - Cliente ID: {$client->id} - " . now()->format('Y-m-d H:i')
            );

            if (!$mkResult['success']) {
                Log::error("Fallo Mikrotik: " . json_encode($mkResult));
                throw new \Exception("Fallo al agregar a Address List en Mikrotik: " . $mkResult['message']);
            }
            
            Log::info("Mikrotik OK: IP agregada/verificada en lista morosos.");

            // 2. Actualizar estado en DB
            $client->service_status = 'suspended';
            $client->save(); // Esto disparará el trait Auditable para el cambio de estado
            Log::info("Estado de cliente actualizado en DB a 'suspended'.");

            // 3. Registro detallado de auditoría técnica (según requerimiento)
            Audit::create([
                'table_name' => 'clients',
                'operation' => 'SUSPEND_TECH_OP',
                'record_id' => (string) $client->id,
                'old_values' => ['service_status' => $oldStatus],
                'new_values' => [
                    'service_status' => 'suspended',
                    'ip' => $client->ip,
                    'mikrotik_operation' => 'add_to_address_list',
                    'mikrotik_list' => 'morosos',
                    'mikrotik_response' => $mkResult,
                    'timestamp' => now()->toIso8601String(),
                    'executor' => Auth::user()->name ?? 'Unknown'
                ],
                'user_id' => Auth::id(),
                'ip_address' => $request->ip(),
            ]);

            DB::commit();
            Log::info("Transacción DB commiteada exitosamente. Proceso finalizado.");

            return response()->json([
                'success' => true, 
                'message' => 'Cliente suspendido exitosamente',
                'details' => $mkResult
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error CRÍTICO suspendiendo cliente ID {$id}", [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'client_ip' => $client->ip ?? 'N/A',
                'user_id' => Auth::id()
            ]);
            
            return response()->json([
                'success' => false, 
                'message' => 'Error interno al suspender: ' . $e->getMessage(),
                'debug_id' => $id
            ], 500);
        }
    }

    /**
     * Activar cliente: Revierte suspensión, actualiza DB y remueve de Mikrotik
     */
    public function activate(Request $request, $id, MikroTikService $mikrotik)
    {
        Log::info("Iniciando proceso de activación para cliente ID: {$id}", ['user' => Auth::id()]);
        
        try {
            $client = Client::findOrFail($id);
        } catch (\Exception $e) {
             Log::error("Cliente no encontrado para activación: {$id}");
             return response()->json(['success' => false, 'message' => 'Cliente no encontrado'], 404);
        }

        // Validaciones previas
        if (!in_array(strtoupper($client->service_status), ['SUSPENDIDO', 'SUSPENDED', 'LIMITADO'])) {
            Log::warning("Intento de activar cliente que no está suspendido: {$id} (Estado: {$client->service_status})");
            return response()->json(['success' => false, 'message' => 'El cliente no está suspendido'], 400);
        }

        if (!$client->ip) {
             Log::warning("Intento de activar cliente sin IP: {$id}");
             return response()->json(['success' => false, 'message' => 'El cliente no tiene IP asignada'], 400);
        }

        // Validar conexión Mikrotik
        try {
            Log::info("Verificando conexión con Mikrotik...");
            $sysInfo = $mikrotik->getSystemInfo();
            if (empty($sysInfo)) {
                throw new \Exception('No hay conexión con el router MikroTik (Respuesta vacía)');
            }
            Log::info("Conexión Mikrotik OK.");
        } catch (\Exception $e) {
             Log::error("Error de conexión Mikrotik previo a activación: " . $e->getMessage());
             return response()->json(['success' => false, 'message' => 'Error de conectividad Mikrotik: ' . $e->getMessage()], 503);
        }

        DB::beginTransaction();
        Log::info("Transacción DB iniciada (Activación).");

        try {
            $oldStatus = $client->service_status;
            
            // 1. Intentar remover bloqueo en Mikrotik (Address List "morosos")
            Log::info("Enviando comando a Mikrotik para remover IP {$client->ip} de address-list 'morosos'");
            
            $mkResult = $mikrotik->removeIpFromAddressList(
                $client->ip, 
                'morosos'
            );

            if (!$mkResult['success']) {
                Log::error("Fallo Mikrotik al remover: " . json_encode($mkResult));
                throw new \Exception("Fallo al remover de Address List en Mikrotik: " . $mkResult['message']);
            }
            
            Log::info("Mikrotik OK: IP removida de lista morosos.");

            // 2. Actualizar estado en DB
            $client->service_status = 'active';
            $client->save(); 
            Log::info("Estado de cliente actualizado en DB a 'active'.");

            // 3. Registro detallado de auditoría técnica
            Audit::create([
                'table_name' => 'clients',
                'operation' => 'ACTIVATE_TECH_OP',
                'record_id' => (string) $client->id,
                'old_values' => ['service_status' => $oldStatus],
                'new_values' => [
                    'service_status' => 'active',
                    'ip' => $client->ip,
                    'mikrotik_operation' => 'remove_from_address_list',
                    'mikrotik_list' => 'morosos',
                    'mikrotik_response' => $mkResult,
                    'timestamp' => now()->toIso8601String(),
                    'executor' => Auth::user()->name ?? 'Unknown'
                ],
                'user_id' => Auth::id(),
                'ip_address' => $request->ip(),
            ]);

            DB::commit();
            Log::info("Transacción DB (Activación) commiteada exitosamente.");

            return response()->json([
                'success' => true, 
                'message' => 'Cliente activado exitosamente',
                'details' => $mkResult
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error CRÍTICO activando cliente ID {$id}", [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'client_ip' => $client->ip ?? 'N/A',
                'user_id' => Auth::id()
            ]);
            
            return response()->json([
                'success' => false, 
                'message' => 'Error interno al activar: ' . $e->getMessage(),
                'debug_id' => $id
            ], 500);
        }
    }

    /**
     * Cancelar cliente: Marca el estado como 'cancelled' (Eliminación lógica)
     */
    public function cancel(Request $request, $id)
    {
        Log::info("Iniciando proceso de cancelación para cliente ID: {$id}", ['user' => Auth::id()]);
        
        try {
            $client = Client::findOrFail($id);
        } catch (\Exception $e) {
             Log::error("Cliente no encontrado para cancelación: {$id}");
             return response()->json(['success' => false, 'message' => 'Cliente no encontrado'], 404);
        }

        // Validación: Solo permitir eliminar si está suspendido
        if (!in_array(strtoupper($client->service_status), ['SUSPENDIDO', 'SUSPENDED'])) {
            Log::warning("Intento de cancelar cliente no suspendido: {$id} (Estado: {$client->service_status})");
            return response()->json([
                'success' => false, 
                'message' => 'Solo se pueden eliminar clientes que estén suspendidos.'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $oldStatus = $client->service_status;
            
            $client->service_status = 'cancelled';
            $client->save();

            Audit::create([
                'table_name' => 'clients',
                'operation' => 'CANCEL_OP',
                'record_id' => (string) $client->id,
                'old_values' => ['service_status' => $oldStatus],
                'new_values' => [
                    'service_status' => 'cancelled',
                    'timestamp' => now()->toIso8601String(),
                    'executor' => Auth::user()->name ?? 'Unknown'
                ],
                'user_id' => Auth::id(),
                'ip_address' => $request->ip(),
            ]);

            DB::commit();
            Log::info("Cliente ID {$id} cancelado exitosamente.");

            return response()->json([
                'success' => true, 
                'message' => 'Cliente eliminado/cancelado exitosamente'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error cancelando cliente ID {$id}: " . $e->getMessage());
            return response()->json([
                'success' => false, 
                'message' => 'Error al cancelar cliente: ' . $e->getMessage()
            ], 500);
        }
    }
}
