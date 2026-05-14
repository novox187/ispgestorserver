<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Wallet;
use App\Models\ClientPlan;
use App\Models\Plan;
use App\Services\MikroTikQueueSyncService;
use App\Services\IspCapacityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class ClienteController extends Controller
{
    public function __construct(
        protected MikroTikQueueSyncService $sync,
        protected IspCapacityService $capacity
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $clientes = Client::with(['servicios.plan', 'soportes'])->get();
        return response()->json($clientes);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'full_name' => ['required', 'string', 'max:255'],
                'document_id' => ['required', 'string', 'max:50', 'unique:clients,document_id'],
                'contact_phone' => ['required', 'string', 'max:20'],
                'email' => ['required', 'email', 'max:255', 'unique:clients,email'],
                'password' => ['required', 'string', 'min:6'],
                'installation_address' => ['required', 'string'],
                'gps_coordinates' => ['nullable', 'string', 'max:50'],
                'contract_date' => ['required', 'date'],
                'service_status' => ['nullable', 'in:ACTIVE,LIMITED,SUSPENDED,CANCELLED,INACTIVE,ACTIVO,LIMITADO,SUSPENDIDO,INACTIVO'],
                'ip' => [
                    'nullable',
                    Rule::requiredIf(fn () => $request->filled('plan_id') || $request->filled('plan')),
                    'ip',
                ],
                'observations' => ['nullable', 'string'],
                'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
                'plan' => ['nullable', 'string'],
            ]);

            if (!empty($data['service_status'])) {
                $map = [
                    'ACTIVE' => 'ACTIVE',
                    'LIMITED' => 'LIMITED',
                    'SUSPENDED' => 'SUSPENDED',
                    'CANCELLED' => 'INACTIVE',
                    'INACTIVE' => 'INACTIVE',
                    'ACTIVO' => 'ACTIVE',
                    'LIMITADO' => 'LIMITED',
                    'SUSPENDIDO' => 'SUSPENDED',
                    'INACTIVO' => 'INACTIVE',
                ];
                $upper = strtoupper($data['service_status']);
                $data['service_status'] = $map[$upper] ?? 'ACTIVE';
            }

            $plan = null;
            if (!empty($data['plan_id']) || !empty($data['plan'])) {
                if (!empty($data['plan_id'])) {
                    $plan = Plan::find($data['plan_id']);
                } else {
                    $plan = Plan::query()
                        ->where('name', $data['plan'])
                        ->orWhere('slug', $data['plan'])
                        ->first();
                }
            }

            if ($plan) {
                $planCapacity = $this->capacity->getPlanCapacity($plan);
                if (!$planCapacity['has_capacity']) {
                    return response()->json([
                        'success' => false,
                        'code' => 'PLAN_CAPACITY_EXHAUSTED',
                        'message' => 'El plan no tiene capacidad disponible para nuevos clientes',
                        'plan_capacity' => $planCapacity,
                    ], 409);
                }
            }

            return DB::transaction(function () use ($data, $plan) {
                $client = Client::create([
                    'full_name' => $data['full_name'],
                    'document_id' => $data['document_id'],
                    'contact_phone' => $data['contact_phone'],
                    'email' => $data['email'],
                    'password' => Hash::make($data['password']),
                    'installation_address' => $data['installation_address'],
                    'gps_coordinates' => $data['gps_coordinates'] ?? null,
                    'contract_date' => $data['contract_date'],
                    'service_status' => $data['service_status'] ?? 'ACTIVE',
                    'ip' => $data['ip'] ?? '0.0.0.0',
                    'observations' => $data['observations'] ?? null,
                ]);

                $wallet = Wallet::create([
                    'client_id' => $client->id,
                    'balance' => 0,
                    'currency' => 'USD',
                    'status' => 'active',
                ]);

                $clientPlan = null;
                if (!empty($data['plan_id']) || !empty($data['plan'])) {
                    if ($plan) {
                        $clientPlan = ClientPlan::create([
                            'client_id' => $client->id,
                            'plan_id' => $plan->id,
                            'status' => 'active',
                            'start_date' => now()->toDateString(),
                            'next_billing_date' => now()->addMonth()->toDateString(),
                            'current_price' => $plan->monthly_price ?? 0,
                            'billing_cycle' => $plan->billing_cycle ?? 'monthly',
                            'ip_address' => $data['ip'] ?? null,
                            'notes' => $data['observations'] ?? null,
                        ]);

                        $syncResult = $this->sync->createClientAndQueue($client, $clientPlan, $plan);
                    }
                }

                return response()->json([
                    'client' => $client,
                    'wallet' => $wallet,
                    'client_plan' => $clientPlan,
                    'sync' => isset($syncResult) ? $syncResult : null,
                ], 201);
            });
        } catch (ValidationException $ve) {
            $errorId = (string) Str::uuid();
            $safeInput = collect($request->all())->except(['password'])->toArray();
            Log::warning('Cliente store validation failed', [
                'error_id' => $errorId,
                'errors' => $ve->errors(),
                'input' => $safeInput,
            ]);
            return response()->json([
                'message' => 'Errores de validación.',
                'errors' => $ve->errors(),
                'error_id' => $errorId,
            ], 422);
        } catch (\Throwable $e) {
            $errorId = (string) Str::uuid();
            $safeInput = collect($request->all())->except(['password'])->toArray();
            Log::error('Cliente store failed', [
                'error_id' => $errorId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input' => $safeInput,
            ]);
            return response()->json([
                'message' => 'Error creando cliente o sincronizando con MikroTik.',
                'error_id' => $errorId,
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 502);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request)
    {
        $authCliente = $request->user();
        if (! $authCliente instanceof Client) {
            return response()->json(['message' => 'No autenticado'], 401);
        }
        // Leer solo las columnas necesarias desde la base de datos
        $data = Client::query()
            ->whereKey($authCliente->getKey())
            ->selectRaw('full_name, email, contact_phone, installation_address, gps_coordinates')
            ->first();

        return response()->json($data);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Client $client)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Client $client)
    {
        //
    }
}
