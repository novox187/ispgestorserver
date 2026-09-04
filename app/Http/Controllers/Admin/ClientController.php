<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Audit;
use App\Services\ClientSuspensionService;
use App\Services\MikroTikQueueSyncService;
use App\Services\MikroTikService;
use App\Services\IspCapacityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ClientController extends Controller
{
    /**
     * Estados de factura que cuentan como deuda viva del cliente.
     * 'draft' y 'cancelled' quedan fuera: la primera aún no se emitió y la
     * segunda ya no se cobra.
     */
    private const DEBT_STATUSES = ['pending', 'failed'];

    /**
     * Variantes de `service_status` que representan un servicio no pleno.
     *
     * El enum de la columna guarda 'LIMITED', pero el código sólo contemplaba
     * la forma castellana 'LIMITADO': un cliente limitado quedaba fuera del
     * filtro, fuera del contador y sin poder reactivarse.
     */
    private const DEGRADED_STATUSES = ['suspended', 'Suspended', 'SUSPENDIDO', 'SUSPENDED', 'LIMITADO', 'LIMITED', 'limited'];

   
   /**
     * Listado resumido de clientes: Nombre, Email, Teléfono, Plan actual, Estado del servicio
     */
    public function listSummary(Request $request)
    {
        $search  = $request->input('search');
        $status  = $request->input('status');
        $perPage = (int) $request->input('per_page', 10);
        $sort    = (string) $request->input('sort', 'id');
        $dir     = strtolower((string) $request->input('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $query = Client::query();

        // Búsqueda
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('document_id', 'like', "%{$search}%")
                  ->orWhere('ip', 'like', "%{$search}%")
                  ->orWhere('contact_phone', 'like', "%{$search}%");
            });
        }

        // Filtro de estado
        if ($status && $status !== 'all') {
            if ($status === 'active') {
                 $query->whereIn('service_status', ['active', 'ACTIVO', 'Active']);
            } elseif ($status === 'suspended') {
                 $query->whereIn('service_status', self::DEGRADED_STATUSES);
            } elseif ($status === 'cancelled') {
                 $query->whereIn('service_status', ['cancelled', 'CANCELADO', 'Cancelled']);
            } elseif ($status === 'inactive') {
                 $query->where(function ($q) {
                     $q->whereIn('service_status', ['inactive', 'INACTIVO', 'Inactive'])
                       ->orWhereNull('service_status');
                 });
            } elseif ($status === 'without_plan') {
                 // Filtrar clientes que NO tienen un plan activo vigente
                 $query->whereDoesntHave('clientPlans', function ($q) {
                     $q->where('status', 'active')
                       ->where(function ($qq) {
                           $qq->whereNull('end_date')
                              ->orWhere('end_date', '>=', now());
                       });
                 });
            } elseif ($status === 'with_debt') {
                 // Cartera vencida: al menos una factura sin cobrar
                 $query->whereHas('invoices', fn ($q) => $q->whereIn('status', self::DEBT_STATUSES));
            } else {
                 $query->where('service_status', $status);
            }
        }

        // Las columnas se fijan antes de los agregados: select() reemplaza la
        // lista entera y dejaría fuera las subconsultas de withSum/withCount.
        $query->select(['id', 'full_name', 'email', 'contact_phone', 'service_status', 'document_id', 'ip', 'contract_date']);

        // Deuda y saldo se agregan en SQL para poder ordenar por ellos sin
        // traer todas las facturas de cada cliente a memoria.
        $query->withSum(
            ['invoices as debt_total' => fn ($q) => $q->whereIn('status', self::DEBT_STATUSES)],
            'total_amount'
        );
        $query->withCount([
            'invoices as debt_count' => fn ($q) => $q->whereIn('status', self::DEBT_STATUSES),
            'invoices as overdue_count' => fn ($q) => $q
                ->whereIn('status', self::DEBT_STATUSES)
                ->whereDate('due_date', '<', now()->toDateString()),
        ]);

        // Ordenación. 'cancelled' siempre al final salvo que se pida un orden
        // explícito por deuda o saldo, donde ese agrupamiento estorba.
        $sortable = [
            'id'        => 'id',
            'name'      => 'full_name',
            'status'    => 'service_status',
            'debt'      => 'debt_total',
            'contract'  => 'contract_date',
        ];

        if ($sort === 'debt') {
            $query->orderByRaw('COALESCE(debt_total, 0) ' . $dir);
        } elseif (isset($sortable[$sort])) {
            $query->orderByRaw("CASE WHEN service_status = 'cancelled' THEN 1 ELSE 0 END")
                  ->orderBy($sortable[$sort], $dir);
        } else {
            $query->orderByRaw("CASE WHEN service_status = 'cancelled' THEN 1 ELSE 0 END")
                  ->orderBy('id');
        }

        // Carga clientes y su plan activo más reciente (si existe)
        $paginator = $query->with(['clientPlans' => function ($q) {
                $q->where('status', 'active')
                  ->where(function ($qq) {
                      $qq->whereNull('end_date')
                         ->orWhere('end_date', '>=', now());
                  })
                  ->orderByDesc('start_date');
            }, 'clientPlans.plan', 'wallet:id,client_id,balance'])
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
                'plan_price' => $currentPlan && $currentPlan->plan ? (float) $currentPlan->plan->monthly_price : null,
                'status' => $client->service_status,
                'ip' => $client->ip,
                'contract_date' => $client->contract_date?->toDateString(),
                'wallet_balance' => (float) ($client->wallet->balance ?? 0),
                'debt_total' => (float) ($client->debt_total ?? 0),
                'debt_count' => (int) ($client->debt_count ?? 0),
                'overdue_count' => (int) ($client->overdue_count ?? 0),
            ];
        });

        // Calcular estadísticas
        $stats = [
            'total' => Client::count(),
            'active' => Client::whereIn('service_status', ['active', 'ACTIVO', 'Active'])->count(),
            'suspended' => Client::whereIn('service_status', self::DEGRADED_STATUSES)->count(),
            'inactive' => Client::where(function($q) {
                 $q->whereIn('service_status', ['inactive', 'INACTIVO', 'Inactive'])
                   ->orWhereNull('service_status');
            })->count(),
            'with_debt' => Client::whereHas('invoices', fn ($q) => $q->whereIn('status', self::DEBT_STATUSES))->count(),
            'without_plan' => Client::whereDoesntHave('clientPlans', function ($q) {
                $q->where('status', 'active')
                  ->where(function ($qq) {
                      $qq->whereNull('end_date')
                         ->orWhere('end_date', '>=', now());
                  });
            })->count(),
            'debt_amount' => (float) \App\Models\Invoice::whereIn('status', self::DEBT_STATUSES)->sum('total_amount'),
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
    public function update(Request $request, $id, MikroTikQueueSyncService $sync, IspCapacityService $capacity)
    {
        $request->validate([
            'full_name' => 'required|string|max:255',
            'document_id' => 'required|string|max:50|unique:clients,document_id,' . $id, // Ignorar ID actual
            'email' => 'required|email|max:255',
            'ip' => 'nullable|ip',
            'plan_id' => 'nullable|integer|exists:plans,id',
            'mikrotik_force_replace' => 'sometimes|boolean',
            'reason' => 'required|string|min:5',
        ]);

        $client = Client::findOrFail($id);
        $previousClientPlan = $client->clientPlans()
            ->with('plan')
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', now());
            })
            ->orderByDesc('start_date')
            ->first();
        $previousPlan = $previousClientPlan?->plan;
        $previousQueueName = $previousClientPlan?->mikrotik_queue_id;
        $previousIp = $previousClientPlan?->ip_address ?: $client->ip;

        if ($request->has('plan_id')) {
            $newPlanId = $request->plan_id ?: null;
            $currentPlanId = $previousClientPlan?->plan_id;
            if ($newPlanId != $currentPlanId) {
                $snapshot = $capacity->getCapacitySnapshot();
                $remaining = (float) ($snapshot['remaining_down_mbps'] ?? 0);

                $deltaNew = 0.0;
                if ($newPlanId) {
                    $newPlan = Plan::findOrFail($newPlanId);
                    $reuseNew = $capacity->getPlanReuseRatio($newPlan);
                    $newCountBefore = (int) \App\Models\ClientPlan::query()
                        ->where('plan_id', $newPlanId)
                        ->where('status', 'active')
                        ->count();
                    $deltaNew = $capacity->calculateNextClientDeltaMbps((float) $newPlan->download_speed, $newCountBefore, $reuseNew);
                }

                $deltaOld = 0.0;
                if ($currentPlanId && $previousPlan) {
                    $reuseOld = $capacity->getPlanReuseRatio($previousPlan);
                    $oldCountBefore = (int) \App\Models\ClientPlan::query()
                        ->where('plan_id', $currentPlanId)
                        ->where('status', 'active')
                        ->count();
                    $beforeOld = $capacity->calculateParentMbps((float) $previousPlan->download_speed, $oldCountBefore, $reuseOld);
                    $afterOld = $capacity->calculateParentMbps((float) $previousPlan->download_speed, max(0, $oldCountBefore - 1), $reuseOld);
                    $deltaOld = $afterOld - $beforeOld;
                }

                $requiredAdditional = max(0.0, $deltaNew + $deltaOld);
                if ($requiredAdditional > 0 && $remaining < $requiredAdditional) {
                    return response()->json([
                        'success' => false,
                        'code' => 'ISP_CAPACITY_EXHAUSTED',
                        'message' => 'Capacidad de ISP agotada',
                        'capacity' => $snapshot,
                    ], 409);
                }
            }
        }
        
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

            $newClientPlan = $client->clientPlans()
                ->with('plan')
                ->where('status', 'active')
                ->where(function ($q) {
                    $q->whereNull('end_date')->orWhere('end_date', '>=', now());
                })
                ->orderByDesc('start_date')
                ->first();
            if ($newClientPlan && $client->ip && filter_var($client->ip, FILTER_VALIDATE_IP) && $newClientPlan->ip_address !== $client->ip) {
                $newClientPlan->update(['ip_address' => $client->ip]);
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
                'user_type' => Auth::user() ? get_class(Auth::user()) : null,
                'ip_address' => $request->ip(),
            ]);

            if ($newClientPlan && $newClientPlan->plan) {
                $sync->syncClientQueueForPlan(
                    $client,
                    $newClientPlan,
                    $newClientPlan->plan,
                    $previousQueueName,
                    $previousIp,
                    $previousPlan,
                    $oldValues['full_name'] ?? null,
                    $oldValues['document_id'] ?? null,
                    (bool) ($request->input('mikrotik_force_replace', false))
                );
            } elseif ($previousClientPlan && $previousPlan) {
                $sync->removeClientQueueAndRecalculate($client, $previousClientPlan, $previousPlan);
            }

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Cliente actualizado correctamente', 'client' => $client]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error actualizando cliente ID {$id}: " . $e->getMessage(), [
                'client_id' => $id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error actualizando cliente: ' . $e->getMessage(),
                'sync_failed' => true,
            ], 503);
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
             $this->auditFailedServiceOperation('SUSPEND_FAILED_OP', $client, $request, 'mikrotik_connectivity', $e->getMessage());
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
            // El observer abre la ventana de corte (fecha límite de facturación)
            // con el ejecutor manual para trazabilidad.
            $client->serviceStatusChangeContext = [
                'reason'   => 'Suspensión manual desde el panel de administración',
                'executor' => 'employee:' . (Auth::id() ?? '?') . ' (' . (Auth::user()->name ?? 'Unknown') . ')',
                'source'   => 'manual',
            ];
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
                'user_type' => Auth::user() ? get_class(Auth::user()) : null,
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

            // El rollback borra cualquier rastro del intento: registrar el
            // fallo fuera de la transacción para que quede en auditoría.
            $this->auditFailedServiceOperation('SUSPEND_FAILED_OP', $client, $request, 'execution', $e->getMessage());

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
        if (!in_array(strtoupper($client->service_status), ['SUSPENDIDO', 'SUSPENDED', 'LIMITADO', 'LIMITED'])) {
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
             $this->auditFailedServiceOperation('ACTIVATE_FAILED_OP', $client, $request, 'mikrotik_connectivity', $e->getMessage());
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
            // El observer cierra la ventana de corte: la facturación se reanuda.
            $client->serviceStatusChangeContext = [
                'reason'   => 'Activación manual desde el panel de administración',
                'executor' => 'employee:' . (Auth::id() ?? '?') . ' (' . (Auth::user()->name ?? 'Unknown') . ')',
                'source'   => 'manual',
            ];
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
                'user_type' => Auth::user() ? get_class(Auth::user()) : null,
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

            // El rollback borra cualquier rastro del intento: registrar el
            // fallo fuera de la transacción para que quede en auditoría.
            $this->auditFailedServiceOperation('ACTIVATE_FAILED_OP', $client, $request, 'execution', $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error interno al activar: ' . $e->getMessage(),
                'debug_id' => $id
            ], 500);
        }
    }

    /**
     * Dar de baja a un cliente (baja lógica).
     *
     * Marca al cliente y sus planes como 'cancelled', libera sus colas en MikroTik
     * y conserva todo el historial. A partir de la baja, el cliente queda excluido
     * de todo proceso automatizado (facturación, suspensión, reactivación).
     * Solo se permite sobre clientes previamente suspendidos.
     */
    public function cancel(Request $request, $id, ClientSuspensionService $suspension)
    {
        Log::info("Iniciando proceso de baja para cliente ID: {$id}", ['user' => Auth::id()]);

        $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $client = Client::findOrFail($id);
        } catch (\Exception $e) {
             Log::error("Cliente no encontrado para baja: {$id}");
             return response()->json(['success' => false, 'message' => 'Cliente no encontrado'], 404);
        }

        $reason = $request->input('reason') ?: 'Baja administrativa';

        try {
            $result = $suspension->cancelClient($client, $reason, Auth::id(), $request->ip());
        } catch (\Throwable $e) {
            Log::error("Error dando de baja al cliente ID {$id}: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al dar de baja al cliente: ' . $e->getMessage(),
            ], 500);
        }

        if (!($result['success'] ?? false)) {
            Log::warning("Baja rechazada para cliente {$id}: " . ($result['message'] ?? 'motivo desconocido'));
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'No se pudo dar de baja al cliente.',
            ], 400);
        }

        Log::info("Cliente ID {$id} dado de baja exitosamente.");

        return response()->json([
            'success' => true,
            'message' => ($result['already_cancelled'] ?? false)
                ? 'El cliente ya estaba dado de baja.'
                : 'Cliente dado de baja exitosamente.',
            'details' => $result['mikrotik'] ?? null,
        ]);
    }

    /**
     * Registra en auditoría un intento fallido de operación de servicio
     * (suspensión/activación). Se ejecuta fuera de la transacción principal
     * y nunca lanza: un fallo aquí no debe alterar la respuesta al cliente.
     */
    private function auditFailedServiceOperation(string $operation, Client $client, Request $request, string $stage, string $error): void
    {
        try {
            Audit::create([
                'table_name' => 'clients',
                'operation'  => $operation,
                'record_id'  => (string) $client->id,
                'old_values' => ['service_status' => $client->service_status],
                'new_values' => [
                    'service_status' => $client->service_status, // sin cambio: el intento falló
                    'ip'             => $client->ip,
                    'failed_stage'   => $stage,
                    'error'          => $error,
                    'timestamp'      => now()->toIso8601String(),
                    'executor'       => Auth::user()->name ?? 'Unknown',
                ],
                'user_id'    => Auth::id(),
                'user_type'  => Auth::user() ? get_class(Auth::user()) : null,
                'ip_address' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::error("No se pudo auditar el intento fallido ({$operation}) del cliente {$client->id}: " . $e->getMessage());
        }
    }
}
