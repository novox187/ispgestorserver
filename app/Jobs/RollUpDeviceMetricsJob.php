<?php

namespace App\Jobs;

use App\Jobs\Concerns\NotifiesWorkerSummary;
use App\Models\AutomationSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Agrega las muestras a resúmenes horarios y poda lo que ya sobra.
 *
 * Con las antenas de cliente dentro del alcance, el detalle crece a unas 100.000
 * filas diarias. Guardarlo todo durante meses haría inviables los backups de la
 * VPS sin que nadie llegue a consultar el dato de las 3:47 de hace medio año.
 * Este job convierte el detalle en mínimos, medias y máximos por hora —que
 * ocupan unas sesenta veces menos y son lo que sirve para ver la tendencia de un
 * enlace que se degrada— y después borra las muestras vencidas.
 *
 * Guarda mínimo y máximo además de la media porque la media sola miente justo en
 * el caso interesante: un enlace que se cae treinta segundos cada hora tiene una
 * media excelente y es el que hay que ir a revisar.
 */
class RollUpDeviceMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, NotifiesWorkerSummary;

    public const SETTING_KEY = 'device_metrics_rollup';

    public int $tries = 1;
    public int $timeout = 600;

    public function failed(\Throwable $exception): void
    {
        $this->notifyWorkerFailure(
            workerName: 'RollUpDeviceMetricsJob',
            exception:  $exception,
            objective:  'Agregar métricas por hora y podar el detalle vencido',
        );
    }

    public function handle(): void
    {
        $setting = AutomationSetting::getCached(self::SETTING_KEY);
        if ($setting && !$setting->enabled) {
            return;
        }

        $sampleDays   = max(1, (int) AutomationSetting::getParam(self::SETTING_KEY, 'samples_retention_days', config('devices.retention.samples_days', 14)));
        $hourlyMonths = max(1, (int) AutomationSetting::getParam(self::SETTING_KEY, 'hourly_retention_months', config('devices.retention.hourly_months', 13)));
        $chunk        = max(500, (int) config('devices.retention.prune_chunk', 5000));

        $this->rollUp();

        $samples = $this->pruneInBatches('device_metric_samples', 'sampled_at', now()->subDays($sampleDays), $chunk);
        $hourly  = $this->pruneInBatches('device_metric_hourly', 'bucket_hour', now()->subMonths($hourlyMonths), $chunk);

        Log::info('RollUpDeviceMetricsJob: mantenimiento de métricas completado.', [
            'samples_pruned' => $samples,
            'hourly_pruned'  => $hourly,
        ]);

        $this->notifyWorkerSummary(
            workerName: 'RollUpDeviceMetricsJob',
            result:     ['muestras_podadas' => $samples, 'agregados_podados' => $hourly],
            objective:  'Agregar métricas por hora y podar el detalle vencido',
        );
    }

    /**
     * Agrega las horas ya cerradas que aún no tengan resumen.
     *
     * Se agrega en SQL y no en PHP porque traer cien mil filas al proceso para
     * promediarlas sería tirar memoria a un problema que el motor resuelve mejor.
     * El `ON DUPLICATE KEY UPDATE` lo hace repetible: si el job corre dos veces
     * sobre la misma hora, recalcula en vez de duplicar.
     *
     * Solo se tocan horas cerradas: agregar la hora en curso daría un resumen
     * parcial que luego habría que rehacer.
     */
    private function rollUp(): void
    {
        $until = now()->startOfHour();
        $from  = $until->copy()->subHours(48);

        DB::statement(<<<'SQL'
            INSERT INTO device_metric_hourly (
                device_id, bucket_hour, sample_count,
                signal_min_dbm, signal_avg_dbm, signal_max_dbm,
                ccq_min_percent, ccq_avg_percent,
                snr_min_db, snr_avg_db,
                cpu_avg_percent, cpu_max_percent,
                created_at, updated_at
            )
            SELECT
                device_id,
                DATE_FORMAT(sampled_at, '%Y-%m-%d %H:00:00') AS bucket_hour,
                COUNT(*),
                MIN(signal_dbm), AVG(signal_dbm), MAX(signal_dbm),
                MIN(ccq_percent), AVG(ccq_percent),
                MIN(snr_db), AVG(snr_db),
                AVG(cpu_load_percent), MAX(cpu_load_percent),
                NOW(), NOW()
            FROM device_metric_samples
            WHERE sampled_at >= ? AND sampled_at < ?
            GROUP BY device_id, bucket_hour
            ON DUPLICATE KEY UPDATE
                sample_count    = VALUES(sample_count),
                signal_min_dbm  = VALUES(signal_min_dbm),
                signal_avg_dbm  = VALUES(signal_avg_dbm),
                signal_max_dbm  = VALUES(signal_max_dbm),
                ccq_min_percent = VALUES(ccq_min_percent),
                ccq_avg_percent = VALUES(ccq_avg_percent),
                snr_min_db      = VALUES(snr_min_db),
                snr_avg_db      = VALUES(snr_avg_db),
                cpu_avg_percent = VALUES(cpu_avg_percent),
                cpu_max_percent = VALUES(cpu_max_percent),
                updated_at      = NOW()
        SQL, [$from, $until]);
    }

    /**
     * Borra en tandas acotadas.
     *
     * Un `DELETE` de millones de filas de un tirón bloquea la tabla y llena el
     * redo log; en una VPS modesta eso es una caída del servicio, no una
     * lentitud. El bucle tiene tope de iteraciones para que un job no pueda
     * quedarse borrando indefinidamente: lo que no dé tiempo hoy se borra en la
     * pasada siguiente.
     */
    private function pruneInBatches(string $table, string $column, \DateTimeInterface $before, int $chunk): int
    {
        $total = 0;

        for ($i = 0; $i < 200; $i++) {
            $deleted = DB::table($table)->where($column, '<', $before)->limit($chunk)->delete();
            $total  += $deleted;

            if ($deleted < $chunk) {
                break;
            }
        }

        return $total;
    }
}
