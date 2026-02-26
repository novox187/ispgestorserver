<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\ClientPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\MikroTikQueueSyncService;
use Throwable;

class PlanController extends Controller
{
    public function __construct(protected MikroTikQueueSyncService $queueSync) {}

    public function index()
    {
        $plans = Plan::with(['features' => function ($q) {
            $q->ordered();
        }])->ordered()->get();

        $data = $plans->map(function (Plan $p) {
            return [
                'id' => $p->id,
                'name' => $p->name,
                'price' => (float) $p->monthly_price,
                'download_speed' => (int) $p->download_speed,
                'upload_speed' => (int) $p->upload_speed,
                'status' => $p->is_active ? 'active' : 'inactive',
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function listSummary(Request $request)
    {
        $plans = Plan::with(['features' => function ($q) {
            $q->ordered();
        }])->ordered()->get();

        $data = $plans->map(function (Plan $p) {
            $clientsCount = ClientPlan::where('plan_id', $p->id)->where('status', 'active')->count();
            $revenue = $clientsCount * (float) $p->monthly_price;
            return [
                'id' => $p->id,
                'name' => $p->name,
                'description' => $p->description,
                'download_speed' => (int) $p->download_speed,
                'upload_speed' => (int) $p->upload_speed,
                'monthly_price' => (float) $p->monthly_price,
                'status' => $p->is_active ? 'active' : 'inactive',
                'clients' => $clientsCount,
                'revenue' => $revenue,
                'symmetric' => (bool) $p->symmetric,
                'setup_price' => (float) $p->setup_price,
                'billing_cycle' => $p->billing_cycle,
                'category' => $p->category,
                'priority' => (int) $p->priority,
                'is_featured' => (bool) $p->is_featured,
                'mikrotik_queue_name' => $p->mikrotik_queue_name,
                'download_limit' => $p->download_limit,
                'upload_limit' => $p->upload_limit,
                'burst_limit' => $p->burst_limit,
                'features' => $p->features->map(function ($f) {
                    return [
                        'feature' => $f->feature,
                        'order' => (int) $f->order,
                        'highlighted' => (bool) $f->highlighted,
                    ];
                })->values(),
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'download_speed' => ['required', 'integer', 'min:0'],
            'upload_speed' => ['required', 'integer', 'min:0'],
            'monthly_price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'symmetric' => ['nullable', 'boolean'],
            'setup_price' => ['nullable', 'numeric', 'min:0'],
            'billing_cycle' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'is_featured' => ['nullable', 'boolean'],
            'mikrotik_queue_name' => ['nullable', 'string', 'max:255'],
            'download_limit' => ['nullable', 'string', 'max:255'],
            'upload_limit' => ['nullable', 'string', 'max:255'],
            'burst_limit' => ['nullable', 'string', 'max:255'],
            'features' => ['nullable', 'array'],
            'features.*.feature' => ['required', 'string', 'max:255'],
            'features.*.order' => ['nullable', 'integer', 'min:0'],
            'features.*.highlighted' => ['nullable', 'boolean'],
        ]);

        DB::beginTransaction();
        try {
            $baseSlug = Str::slug($validated['name']);
            $slug = $baseSlug;
            $suffix = 1;
            while (Plan::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $suffix++;
            }
            $plan = new Plan();
            $plan->fill([
                'name' => $validated['name'],
                'slug' => $slug,
                'description' => $validated['description'] ?? null,
                'download_speed' => $validated['download_speed'],
                'upload_speed' => $validated['upload_speed'],
                'monthly_price' => $validated['monthly_price'],
                'is_active' => $validated['is_active'],
                'priority' => 1,
                'category' => 'residential',
                'billing_cycle' => 'monthly',
                'symmetric' => false,
                'setup_price' => 0,
            ]);
            if (array_key_exists('symmetric', $validated)) $plan->symmetric = (bool) $validated['symmetric'];
            if (array_key_exists('setup_price', $validated)) $plan->setup_price = (float) $validated['setup_price'];
            if (array_key_exists('billing_cycle', $validated)) $plan->billing_cycle = $validated['billing_cycle'];
            if (array_key_exists('category', $validated)) $plan->category = $validated['category'];
            if (array_key_exists('priority', $validated)) $plan->priority = (int) $validated['priority'];
            if (array_key_exists('is_featured', $validated)) $plan->is_featured = (bool) $validated['is_featured'];
            if (array_key_exists('mikrotik_queue_name', $validated)) $plan->mikrotik_queue_name = $validated['mikrotik_queue_name'];
            if (array_key_exists('download_limit', $validated)) $plan->download_limit = $validated['download_limit'];
            if (array_key_exists('upload_limit', $validated)) $plan->upload_limit = $validated['upload_limit'];
            if (array_key_exists('burst_limit', $validated)) $plan->burst_limit = $validated['burst_limit'];
            $plan->save();

            $features = $validated['features'] ?? [];
            foreach ($features as $i => $f) {
                $plan->features()->create([
                    'feature' => $f['feature'],
                    'order' => $f['order'] ?? $i,
                    'highlighted' => $f['highlighted'] ?? false,
                ]);
            }

            $mkResult = $this->queueSync->ensurePlanQueue($plan);
            if (($mkResult['action'] ?? '') === 'failed') {
                throw new \RuntimeException($mkResult['reason'] ?? 'Fallo al crear plan en MikroTik');
            }

            DB::commit();

            $clientsCount = ClientPlan::where('plan_id', $plan->id)->where('status', 'active')->count();
            $revenue = $clientsCount * (float) $plan->monthly_price;
            $out = [
                'id' => $plan->id,
                'name' => $plan->name,
                'description' => $plan->description,
                'download_speed' => (int) $plan->download_speed,
                'upload_speed' => (int) $plan->upload_speed,
                'monthly_price' => (float) $plan->monthly_price,
                'status' => $plan->is_active ? 'active' : 'inactive',
                'clients' => $clientsCount,
                'revenue' => $revenue,
                'symmetric' => (bool) $plan->symmetric,
                'setup_price' => (float) $plan->setup_price,
                'billing_cycle' => $plan->billing_cycle,
                'category' => $plan->category,
                'priority' => (int) $plan->priority,
                'is_featured' => (bool) $plan->is_featured,
                'mikrotik_queue_name' => $plan->mikrotik_queue_name,
                'download_limit' => $plan->download_limit,
                'upload_limit' => $plan->upload_limit,
                'burst_limit' => $plan->burst_limit,
                'features' => $plan->features()->ordered()->get()->map(function ($f) {
                    return [
                        'feature' => $f->feature,
                        'order' => (int) $f->order,
                        'highlighted' => (bool) $f->highlighted,
                    ];
                })->values(),
                'plan_queue' => [
                    'action' => $mkResult['action'] ?? null,
                    'name' => $mkResult['name'] ?? null,
                ],
            ];

            return response()->json(['data' => $out], 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Plan creation rollback due to MikroTik failure', [
                'error_code' => 'MIKROTIK_CREATE_FAILED',
                'message' => $e->getMessage(),
                'when' => now()->toDateTimeString(),
                'plan_payload' => $validated,
            ]);
            return response()->json([
                'error' => [
                    'code' => 'MIKROTIK_CREATE_FAILED',
                    'message' => 'La creación en MikroTik falló y se revirtió la inserción en la base de datos.',
                    'details' => $e->getMessage(),
                    'recommendations' => 'Verifique credenciales y conectividad con MikroTik, y valide límites/priority.',
                ]
            ], 500);
        }
    }

    public function update(Request $request, int $id)
    {
        $plan = Plan::findOrFail($id);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'download_speed' => ['required', 'integer', 'min:0'],
            'upload_speed' => ['required', 'integer', 'min:0'],
            'monthly_price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'symmetric' => ['nullable', 'boolean'],
            'setup_price' => ['nullable', 'numeric', 'min:0'],
            'billing_cycle' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'is_featured' => ['nullable', 'boolean'],
            'mikrotik_queue_name' => ['nullable', 'string', 'max:255'],
            'download_limit' => ['nullable', 'string', 'max:255'],
            'upload_limit' => ['nullable', 'string', 'max:255'],
            'burst_limit' => ['nullable', 'string', 'max:255'],
            'features' => ['nullable', 'array'],
            'features.*.feature' => ['required', 'string', 'max:255'],
            'features.*.order' => ['nullable', 'integer', 'min:0'],
            'features.*.highlighted' => ['nullable', 'boolean'],
        ]);

        $plan->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? $plan->description,
            'download_speed' => $validated['download_speed'],
            'upload_speed' => $validated['upload_speed'],
            'monthly_price' => $validated['monthly_price'],
            'is_active' => $validated['is_active'],
        ]);
        if (array_key_exists('symmetric', $validated)) $plan->symmetric = (bool) $validated['symmetric'];
        if (array_key_exists('setup_price', $validated)) $plan->setup_price = (float) $validated['setup_price'];
        if (array_key_exists('billing_cycle', $validated)) $plan->billing_cycle = $validated['billing_cycle'];
        if (array_key_exists('category', $validated)) $plan->category = $validated['category'];
        if (array_key_exists('priority', $validated)) $plan->priority = (int) $validated['priority'];
        if (array_key_exists('is_featured', $validated)) $plan->is_featured = (bool) $validated['is_featured'];
        if (array_key_exists('mikrotik_queue_name', $validated)) $plan->mikrotik_queue_name = $validated['mikrotik_queue_name'];
        if (array_key_exists('download_limit', $validated)) $plan->download_limit = $validated['download_limit'];
        if (array_key_exists('upload_limit', $validated)) $plan->upload_limit = $validated['upload_limit'];
        if (array_key_exists('burst_limit', $validated)) $plan->burst_limit = $validated['burst_limit'];
        $plan->save();

        if (array_key_exists('features', $validated)) {
            $plan->features()->delete();
            $features = $validated['features'] ?? [];
            foreach ($features as $i => $f) {
                $plan->features()->create([
                    'feature' => $f['feature'],
                    'order' => $f['order'] ?? $i,
                    'highlighted' => $f['highlighted'] ?? false,
                ]);
            }
        }

        $clientsCount = ClientPlan::where('plan_id', $plan->id)->where('status', 'active')->count();
        $revenue = $clientsCount * (float) $plan->monthly_price;
        $out = [
            'id' => $plan->id,
            'name' => $plan->name,
            'description' => $plan->description,
            'download_speed' => (int) $plan->download_speed,
            'upload_speed' => (int) $plan->upload_speed,
            'monthly_price' => (float) $plan->monthly_price,
            'status' => $plan->is_active ? 'active' : 'inactive',
            'clients' => $clientsCount,
            'revenue' => $revenue,
            'symmetric' => (bool) $plan->symmetric,
            'setup_price' => (float) $plan->setup_price,
            'billing_cycle' => $plan->billing_cycle,
            'category' => $plan->category,
            'priority' => (int) $plan->priority,
            'is_featured' => (bool) $plan->is_featured,
            'mikrotik_queue_name' => $plan->mikrotik_queue_name,
            'download_limit' => $plan->download_limit,
            'upload_limit' => $plan->upload_limit,
            'burst_limit' => $plan->burst_limit,
            'features' => $plan->features()->ordered()->get()->map(function ($f) {
                return [
                    'feature' => $f->feature,
                    'order' => (int) $f->order,
                    'highlighted' => (bool) $f->highlighted,
                ];
            })->values(),
        ];

        return response()->json(['data' => $out]);
    }

    public function setStatus(Request $request, int $id)
    {
        $plan = Plan::findOrFail($id);
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);
        $plan->is_active = $validated['is_active'];
        $plan->save();

        $clientsCount = ClientPlan::where('plan_id', $plan->id)->where('status', 'active')->count();
        $revenue = $clientsCount * (float) $plan->monthly_price;
        $out = [
            'id' => $plan->id,
            'name' => $plan->name,
            'description' => $plan->description,
            'download_speed' => (int) $plan->download_speed,
            'upload_speed' => (int) $plan->upload_speed,
            'monthly_price' => (float) $plan->monthly_price,
            'status' => $plan->is_active ? 'active' : 'inactive',
            'clients' => $clientsCount,
            'revenue' => $revenue,
            'features' => $plan->features()->ordered()->get()->map(function ($f) {
                return [
                    'feature' => $f->feature,
                    'order' => (int) $f->order,
                    'highlighted' => (bool) $f->highlighted,
                ];
            })->values(),
        ];

        return response()->json(['data' => $out]);
    }
}
