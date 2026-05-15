<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Audit;
use App\Models\AutomationSetting;
use App\Services\AutomationSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

class AutomationController extends Controller
{
    public function __construct(private readonly AutomationSettingsService $service) {}

    public function index()
    {
        return response()->json(AutomationSetting::orderBy('id')->get());
    }

    public function show(string $key)
    {
        $setting = AutomationSetting::where('key', $key)->firstOrFail();
        return response()->json($setting);
    }

    public function update(Request $request, string $key)
    {
        $setting = AutomationSetting::where('key', $key)->firstOrFail();

        $data = $request->validate([
            'enabled'                  => ['sometimes', 'boolean'],
            'schedule_type'            => ['sometimes', 'string'],
            'schedule_config'          => ['sometimes', 'array'],
            'schedule_config.time'     => ['sometimes', 'string'],
            'schedule_config.day'      => ['sometimes', 'integer'],
            'schedule_config.expression' => ['sometimes', 'string'],
            'params'                   => ['sometimes', 'array'],
        ]);

        if (isset($data['schedule_type']) || isset($data['schedule_config'])) {
            $type = $data['schedule_type'] ?? $setting->schedule_type;
            $config = $data['schedule_config'] ?? $setting->schedule_config ?? [];
            $scheduleErrors = $this->service->validateSchedule($type, $config);
            if (!empty($scheduleErrors)) {
                return response()->json(['errors' => $scheduleErrors], 422);
            }
        }

        if (isset($data['params'])) {
            $paramErrors = $this->service->validateParams($setting, $data['params']);
            if (!empty($paramErrors)) {
                return response()->json(['errors' => $paramErrors], 422);
            }
        }

        $setting->update($data);

        return response()->json($setting->fresh());
    }

    public function audits(string $key)
    {
        $setting = AutomationSetting::where('key', $key)->firstOrFail();

        $audits = Audit::where('table_name', $setting->getTable())
            ->where('record_id', (string) $setting->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(function (Audit $a) {
                $user = null;
                if ($a->user_id && $a->user_type) {
                    $user = $a->user_type::find($a->user_id);
                }
                return [
                    'id'          => $a->id,
                    'operation'   => $a->operation,
                    'old_values'  => $a->old_values,
                    'new_values'  => $a->new_values,
                    'user_id'     => $a->user_id,
                    'user_name'   => $user?->name ?? $user?->nombre ?? null,
                    'user_email'  => $user?->email ?? null,
                    'ip_address'  => $a->ip_address,
                    'created_at'  => $a->created_at,
                ];
            });

        return response()->json($audits);
    }

    public function runNow(string $key)
    {
        $setting = AutomationSetting::where('key', $key)->firstOrFail();

        if (!$setting->enabled) {
            return response()->json(['message' => 'Esta automatización está deshabilitada'], 400);
        }

        if (!class_exists($setting->job_class)) {
            return response()->json(['message' => "La clase {$setting->job_class} no existe"], 500);
        }

        $jobInstance = new $setting->job_class();
        Bus::dispatch($jobInstance->onQueue($setting->queue));

        $setting->updateQuietly(['last_run_at' => now()]);

        return response()->json([
            'message' => "Job '{$setting->name}' despachado a la cola '{$setting->queue}'.",
        ]);
    }
}
