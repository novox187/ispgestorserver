<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Audit;
use App\Models\AutomationSetting;
use App\Services\AutomationPolicyService;
use App\Services\AutomationSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

class AutomationController extends Controller
{
    public function __construct(
        private readonly AutomationSettingsService $service,
        private readonly AutomationPolicyService $policy,
    ) {}

    public function index()
    {
        $settings = AutomationSetting::orderBy('id')->get();

        return response()->json([
            // El panel necesita, además del estado, saber qué se puede tocar:
            // sin la política tendría que volver a codificar los límites en el
            // navegador, que es justo como se llegó a poder configurar la
            // facturación mensual «cada cinco minutos».
            'data'        => $settings->map(fn (AutomationSetting $s) => $this->withPolicy($s)),
            'warnings'    => $this->policy->coherenceWarnings($settings),
            'risk_levels' => config('automations.risk_levels'),
        ]);
    }

    public function show(string $key)
    {
        $setting = AutomationSetting::where('key', $key)->firstOrFail();
        return response()->json($this->withPolicy($setting));
    }

    /**
     * A cuántos clientes afectaría un cambio antes de aplicarlo.
     */
    public function impact(Request $request, string $key)
    {
        $setting = AutomationSetting::where('key', $key)->firstOrFail();

        $params = $request->validate([
            'params' => ['sometimes', 'array'],
        ])['params'] ?? [];

        return response()->json($this->policy->previewImpact($setting, $params));
    }

    private function withPolicy(AutomationSetting $setting): array
    {
        $policy = $this->policy->policyFor($setting->key);

        return $setting->toArray() + [
            'policy' => [
                'risk'               => $policy['risk'],
                'summary'            => $policy['summary'],
                'if_disabled'        => $policy['if_disabled'],
                'why_schedule'       => $policy['why_schedule'],
                'allowed_schedules'  => $this->policy->allowedSchedules($setting),
                'supports_impact'    => $setting->key === 'client_suspension',
            ],
        ];
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

            // Qué cadencias admite ESTE worker. Ocultarlas en el desplegable no
            // basta: la API se llama igual desde fuera del panel.
            $policyErrors = $this->policy->validateScheduleType($setting, $type);
            if (!empty($policyErrors)) {
                return response()->json(['errors' => $policyErrors], 422);
            }

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

        return response()->json($this->withPolicy($setting->fresh()));
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
                // Resolución validada contra la lista blanca de clases del modelo
                $user = $a->resolveUser();
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
