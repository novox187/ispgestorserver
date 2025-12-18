<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\ClientPlan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
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
