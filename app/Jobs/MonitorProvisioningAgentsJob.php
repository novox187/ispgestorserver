<?php

namespace App\Jobs;

use App\Models\AutomationSetting;
use App\Models\ProvisioningAgent;
use App\Notifications\Core\Facades\Notify;
use App\Notifications\Messages\ProvisioningAgentOfflineNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Vigila que los agentes de aprovisionamiento sigan reportando.
 *
 * Un agente caído no rompe nada de forma visible: simplemente deja de haber
 * altas. Es un fallo silencioso, y por eso hace falta vigilarlo activamente en
 * lugar de esperar a que alguien note que un router enchufado no aparece.
 *
 * Sigue el mismo patrón que `MonitorMikrotikConnectivityJob`: umbral
 * configurable, aviso solo en la transición y deduplicación en el propio
 * mensaje para no repetir la alerta mientras dura el incidente.
 */
class MonitorProvisioningAgentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const SETTING_KEY = 'provisioning_agent_monitor';

    public int $tries = 1;
    public int $timeout = 120;

    public function handle(): void
    {
        $setting = AutomationSetting::getCached(self::SETTING_KEY);
        if ($setting && !$setting->enabled) {
            return;
        }

        $threshold = max(1, (int) AutomationSetting::getParam(
            self::SETTING_KEY,
            'offline_after_minutes',
            config('provisioning.agent.offline_after_minutes', 5),
        ));

        $cutoff = now()->subMinutes($threshold);

        $agents = ProvisioningAgent::query()
            ->active()
            ->whereNotNull('enrolled_at')
            ->get();

        foreach ($agents as $agent) {
            try {
                $isOffline = $agent->last_seen_at === null || $agent->last_seen_at->lt($cutoff);

                if (!$isOffline) {
                    continue;
                }

                Log::channel('provisioning')->warning(
                    "Agente '{$agent->name}' sin reportar desde hace más de {$threshold} min.",
                    [
                        'agent_id'     => $agent->id,
                        'role'         => $agent->role->value,
                        'last_seen_at' => $agent->last_seen_at?->toIso8601String(),
                    ],
                );

                // La ventana de deduplicación del módulo evita repetir esta
                // alerta mientras el incidente siga abierto.
                Notify::dispatch(ProvisioningAgentOfflineNotification::build($agent));
            } catch (Throwable $e) {
                Log::error('MonitorProvisioningAgentsJob: excepción procesando agente.', [
                    'agent_id' => $agent->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }
}
