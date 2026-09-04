<?php

namespace App\Jobs;

use App\Models\AutomationSetting;
use App\Models\NetworkDevice;
use App\Models\ProvisioningAgent;
use App\Notifications\Core\Facades\Notify;
use App\Notifications\Messages\MikrotikDisconnectedNotification;
use App\Services\Devices\ConnectivityRecorder;
use App\Services\Devices\DeviceCapability;
use App\Services\Devices\DeviceDriver;
use App\Services\Devices\DeviceDriverRegistry;
use App\Services\Devices\TelemetryRecorder;
use App\Services\Devices\Dto\DeviceTelemetry;
use App\Services\Devices\Dto\ProbeResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verifica la conectividad de todos los equipos activos del inventario,
 * cualquiera que sea su fabricante.
 *
 * Sucede a `MonitorMikrotikConnectivityJob`, que solo miraba routers MikroTik y
 * hablaba RouterOS directamente. Ahora delega en el `DeviceDriver` de cada
 * equipo, así que dar de alta un fabricante nuevo no obliga a tocar este archivo.
 *
 * Política de alertado (parámetros editables desde Configuraciones → Workers):
 *  - `consecutive_failures_threshold`: fallos seguidos antes de marcar
 *    `disconnected` y alertar. Evita falsos positivos por timeouts puntuales.
 *  - `health_check_timeout_seconds`: timeout de cada sondeo individual.
 *
 * La transición disconnected → connected emite un informativo, y el deduplicador
 * suprime alertas repetidas dentro de su ventana.
 */
class MonitorDeviceConnectivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const SETTING_KEY = 'device_connectivity_monitor';

    /**
     * Clave anterior, aún consultada como respaldo.
     *
     * La migración que renombra la fila y el despliegue del código no ocurren en
     * el mismo instante. Sin este respaldo, el monitor quedaría apagado en
     * silencio en la ventana entre ambos —`AutomationSettingsService` degrada con
     * un `Log::warning`, no con un error visible— y nadie se enteraría hasta que
     * cayera un router y no llegara la alerta.
     */
    public const LEGACY_SETTING_KEY = 'mikrotik_connectivity_monitor';

    public int $tries = 1;
    public int $timeout = 300;

    public function handle(DeviceDriverRegistry $registry): void
    {
        $key = $this->settingKey();

        $setting = AutomationSetting::getCached($key);
        if ($setting && !$setting->enabled) {
            return;
        }

        $threshold      = max(1, (int) AutomationSetting::getParam($key, 'consecutive_failures_threshold', 2));
        $timeoutSeconds = max(1, (int) AutomationSetting::getParam($key, 'health_check_timeout_seconds', 3));

        $offlineAgentIds = $this->offlineAgentIds();

        NetworkDevice::query()->active()->with('agent')->each(
            function (NetworkDevice $device) use ($registry, $threshold, $timeoutSeconds, $offlineAgentIds) {
                try {
                    /*
                     * Dos modos de sondeo conviven. Los routers los alcanza el
                     * propio servidor por el túnel; las antenas viven en la LAN
                     * del cliente y las sondea un agente que empuja las muestras.
                     * En el segundo caso aquí no se sondea nada: se juzga lo que
                     * el agente ya reportó.
                     */
                    if ($device->agent_id !== null) {
                        $this->judgeAgentMonitored($device, $threshold, $offlineAgentIds);

                        return;
                    }

                    $driver = $registry->for($device);

                    if ($driver === null || !$driver->supports(DeviceCapability::PROBE)) {
                        // Una fila puede apuntar a un driver que aún no existe
                        // (un fabricante a medio incorporar). Se salta y se sigue:
                        // abortar el ciclo dejaría sin vigilar al resto del parque.
                        Log::debug('MonitorDeviceConnectivityJob: equipo sin driver que lo sondee.', [
                            'device_id' => $device->id,
                            'driver'    => $device->driver,
                        ]);

                        return;
                    }

                    $this->checkSingle($device, $driver, $threshold, $timeoutSeconds);
                } catch (Throwable $e) {
                    Log::error('MonitorDeviceConnectivityJob: excepción procesando equipo.', [
                        'device_id' => $device->id,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }
        );
    }

    /**
     * Agentes que llevan demasiado sin dar señales.
     *
     * @return array<int, true>
     */
    private function offlineAgentIds(): array
    {
        $minutes = max(1, (int) AutomationSetting::getParam(
            MonitorProvisioningAgentsJob::SETTING_KEY,
            'offline_after_minutes',
            config('provisioning.agent.offline_after_minutes', 5),
        ));

        $cutoff = now()->subMinutes($minutes);

        return ProvisioningAgent::query()
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cutoff))
            ->pluck('id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /**
     * Decide el estado de un equipo que sondea un agente.
     *
     * **Este método es la diferencia entre un monitoreo útil y uno que nadie
     * mira.** Si el agente que vigila trescientas antenas se cae, las
     * trescientas dejan de reportar a la vez. Tratar eso como trescientas caídas
     * genera trescientas alertas que entierran cualquier incidencia real y
     * enseñan al operador a ignorar el canal. Cuando el agente está caído, los
     * equipos pasan a `stale` —«no lo sé»— y la única alerta que sale es la del
     * agente, que ya emite `MonitorProvisioningAgentsJob` y es la que de verdad
     * describe el problema.
     *
     * @param array<int, true> $offlineAgentIds
     */
    private function judgeAgentMonitored(NetworkDevice $device, int $threshold, array $offlineAgentIds): void
    {
        if (isset($offlineAgentIds[$device->agent_id])) {
            if ($device->connectivity_status !== NetworkDevice::STATUS_STALE) {
                $device->forceFill(['connectivity_status' => NetworkDevice::STATUS_STALE])->save();
            }

            return;
        }

        // El agente responde pero nunca ha reportado sobre este equipo: aún no
        // hay nada que juzgar.
        if ($device->last_telemetry_at === null) {
            return;
        }

        if ($device->connectivity_status === NetworkDevice::STATUS_DISCONNECTED) {
            return;
        }

        if ((int) $device->consecutive_failures < $threshold) {
            return;
        }

        $device->forceFill([
            'connectivity_status'  => NetworkDevice::STATUS_DISCONNECTED,
            'last_disconnected_at' => now(),
        ])->save();

        Notify::dispatch(MikrotikDisconnectedNotification::build(
            router:          $device->refresh(),
            errorDetail:     'el agente de monitoreo no obtuvo respuesta del equipo',
            lastConnectedAt: $device->last_connected_at,
        ));
    }

    /**
     * La fila nueva manda; si la migración todavía no ha corrido, se usa la
     * histórica.
     */
    private function settingKey(): string
    {
        return AutomationSetting::getCached(self::SETTING_KEY) !== null
            ? self::SETTING_KEY
            : self::LEGACY_SETTING_KEY;
    }

    private function checkSingle(
        NetworkDevice $device,
        DeviceDriver $driver,
        int $threshold,
        int $timeoutSeconds,
    ): void {
        $recorder = app(ConnectivityRecorder::class);

        /*
         * Se pide la telemetría y no solo el sondeo, cuando el driver la
         * soporta, porque **son la misma llamada al equipo**: en RouterOS las dos
         * leen `/system/resource`, y la diferencia está solo en cuánto se
         * aprovecha de la respuesta. Sondear a secas tiraba la carga de CPU y la
         * memoria que el router acababa de contar, y el panel enseñaba de él dos
         * guiones donde de las antenas enseña barras. Un router ahogado de CPU es
         * justo la avería que conviene ver venir antes de que deje de responder.
         */
        $telemetry = $driver->supports(DeviceCapability::TELEMETRY)
            ? $driver->telemetry($device, $timeoutSeconds)
            : null;

        $result = $telemetry === null
            ? $driver->probe($device, $timeoutSeconds)
            : $this->asProbeResult($telemetry);

        // Anotar el resultado es lo mismo lo haga este ciclo o el botón de
        // «probar credenciales» del panel, así que vive en un sitio compartido.
        // La política de alertado —el umbral— se queda aquí, que es de quien es.
        $result->ok
            ? $recorder->recordUp($device, $result)
            : $recorder->recordDown($device, $result, $threshold);

        if ($telemetry !== null && $result->ok) {
            // Un fallo al anotar la serie no puede tumbar el ciclo que vigila el
            // parque: la métrica es un extra, la conectividad no.
            try {
                app(TelemetryRecorder::class)->record($device, $telemetry);
            } catch (Throwable $e) {
                Log::debug('MonitorDeviceConnectivityJob: no se pudo guardar la telemetría.', [
                    'device_id' => $device->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Traduce una lectura a la respuesta de un sondeo.
     *
     * Un equipo que contesta algo que no sabemos interpretar cuenta como VIVO,
     * igual que hace cada driver en su `probe()`: respondió. Tratar un firmware
     * desconocido como una caída llenaría el canal de alertas falsas cada vez
     * que el cliente actualizara una tanda de equipos.
     */
    private function asProbeResult(DeviceTelemetry $telemetry): ProbeResult
    {
        if (!$telemetry->reachable) {
            return ProbeResult::down($telemetry->error ?? 'sin respuesta');
        }

        return ProbeResult::up(
            model:         $telemetry->model,
            firmware:      $telemetry->firmware,
            uptimeSeconds: $telemetry->uptimeSeconds,
        );
    }
}
