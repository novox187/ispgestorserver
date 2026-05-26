<?php

namespace App\Jobs;

use App\Models\AutomationSetting;
use App\Models\MikrotikRouter;
use App\Notifications\Core\Facades\Notify;
use App\Notifications\Messages\MikrotikDisconnectedNotification;
use App\Notifications\Messages\MikrotikRecoveredNotification;
use App\Services\MikrotikHealthChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job programado que verifica la conectividad de todos los routers MikroTik
 * activos en la base de datos.
 *
 * Política de alertado (params editables desde Configuraciones → Workers
 * Automáticos → Monitor de Conectividad MikroTik):
 *  - `consecutive_failures_threshold`: cantidad de fallos seguidos antes de
 *    marcar `disconnected` y emitir alerta. Evita falsos positivos.
 *  - `health_check_timeout_seconds`: timeout del chequeo individual.
 *
 *  - La transición disconnected → connected emite un mensaje informativo.
 *  - El deduplicador suprime alertas repetidas dentro de la ventana (10 min por defecto).
 */
class MonitorMikrotikConnectivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const SETTING_KEY = 'mikrotik_connectivity_monitor';

    public int $tries = 1;
    public int $timeout = 300;

    public function handle(MikrotikHealthChecker $checker): void
    {
        $setting = AutomationSetting::getCached(self::SETTING_KEY);
        if ($setting && !$setting->enabled) {
            return;
        }

        $threshold = max(1, (int) AutomationSetting::getParam(
            self::SETTING_KEY,
            'consecutive_failures_threshold',
            2
        ));
        $timeoutSeconds = max(1, (int) AutomationSetting::getParam(
            self::SETTING_KEY,
            'health_check_timeout_seconds',
            3
        ));

        $routers = MikrotikRouter::query()->where('is_active', true)->get();

        foreach ($routers as $router) {
            try {
                $this->checkSingle($router, $checker, $threshold, $timeoutSeconds);
            } catch (Throwable $e) {
                Log::error('MonitorMikrotikConnectivityJob: excepción procesando router.', [
                    'router_id' => $router->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    private function checkSingle(
        MikrotikRouter $router,
        MikrotikHealthChecker $checker,
        int $threshold,
        int $timeoutSeconds,
    ): void {
        $result = $checker->check($router, $timeoutSeconds);
        $now    = now();

        if ($result['ok']) {
            $wasDisconnected = $router->connectivity_status === 'disconnected';
            $lastDisconnectedAt = $router->last_disconnected_at;

            $router->forceFill([
                'connectivity_status'   => 'connected',
                'last_health_check_at'  => $now,
                'last_connected_at'     => $now,
                'consecutive_failures'  => 0,
            ])->save();

            if ($wasDisconnected) {
                Notify::dispatch(MikrotikRecoveredNotification::build($router, $lastDisconnectedAt));
            }
            return;
        }

        $newFailures = ((int) $router->consecutive_failures) + 1;
        $router->forceFill([
            'last_health_check_at' => $now,
            'consecutive_failures' => $newFailures,
        ])->save();

        if ($newFailures < $threshold) {
            return;
        }

        // Umbral alcanzado: marcar como desconectado (si no lo estaba ya) y notificar.
        $wasConnected = $router->connectivity_status !== 'disconnected';
        if ($wasConnected) {
            $router->forceFill([
                'connectivity_status'  => 'disconnected',
                'last_disconnected_at' => $now,
            ])->save();
        }

        Notify::dispatch(MikrotikDisconnectedNotification::build(
            router:          $router->refresh(),
            errorDetail:     (string) ($result['error'] ?? 'unknown'),
            lastConnectedAt: $router->last_connected_at,
        ));
    }
}
