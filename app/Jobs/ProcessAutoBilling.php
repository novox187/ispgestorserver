<?php

namespace App\Jobs;

use App\Models\AutomationSetting;
use App\Services\AutoBillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Cobros automáticos — procesa el pago de facturas próximas a vencer y reintentos
 * de facturas fallidas. Los parámetros operativos (frecuencia, ventanas de reintento,
 * montos mínimos/máximos, días de gracia, reglas de notificación) se leen del registro
 * `auto_billing` en la tabla `automation_settings`.
 */
class ProcessAutoBilling implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $backoff = 300;
    public int $timeout = 600;

    public function handle(AutoBillingService $billing): void
    {
        // Lectura fresca: el flag `enabled` debe respetarse en el próximo disparo
        // aunque el cache esté caliente. flushCache() invalida y getCached() recarga.
        AutomationSetting::flushCache();
        $automation = AutomationSetting::getCached('auto_billing');

        $isEnabled = (bool) ($automation->enabled ?? false);

        Log::info('ProcessAutoBilling: estado de configuración al iniciar.', [
            'enabled'         => $isEnabled,
            'has_setting_row' => $automation !== null,
            'params'          => $automation?->params ?? [],
            'schedule_type'   => $automation?->schedule_type,
            'schedule_config' => $automation?->schedule_config,
            'last_run_at'     => $automation?->last_run_at?->toIso8601String(),
        ]);

        if (!$automation) {
            Log::warning('ProcessAutoBilling: no existe el registro AutomationSetting "auto_billing". Job abortado.');
            return;
        }

        if (!$isEnabled) {
            Log::info('ProcessAutoBilling: los Cobros Automáticos están DESACTIVADOS. Ningún cargo será procesado en esta ejecución.');
            return;
        }

        $config = $automation->params ?? [];

        // updateQuietly evita generar auditoría por cada ejecución del job
        $automation->updateQuietly(['last_run_at' => now()]);

        try {
            $result = $billing->processAutoPayments($config);
        } catch (\Throwable $e) {
            Log::error('ProcessAutoBilling: excepción durante el procesamiento de cobros.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        $processed = $result['processed'] ?? [];
        $successful = 0;
        $failed     = 0;

        foreach ($processed as $row) {
            if (($row['result']['success'] ?? false) === true) {
                $successful++;
            } else {
                $failed++;
            }
        }

        Log::info('ProcessAutoBilling finalizado.', [
            'total_processed'      => count($processed),
            'successful'           => $successful,
            'failed'               => $failed,
            'retried_successfully' => $result['retried_successfully'] ?? 0,
            'skipped_amount'       => $result['skipped_amount'] ?? 0,
            'skipped_max_retries'  => $result['skipped_max_retries'] ?? 0,
        ]);
    }
}
