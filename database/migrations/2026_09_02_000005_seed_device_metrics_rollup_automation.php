<?php

use App\Jobs\RollUpDeviceMetricsJob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Da de alta el worker de agregado y poda en las instalaciones ya desplegadas.
 *
 * `AutomationSettingsSeeder` solo cubre las instalaciones nuevas, y este worker
 * no es opcional: sin él la tabla de muestras crece sin freno hasta que los
 * backups dejan de caber. Sembrarlo desde una migración garantiza que se active
 * en el mismo despliegue que introduce las tablas que tiene que podar.
 */
return new class extends Migration
{
    private const KEY = 'device_metrics_rollup';

    public function up(): void
    {
        if (DB::table('automation_settings')->where('key', self::KEY)->exists()) {
            return;
        }

        DB::table('automation_settings')->insert([
            'key'             => self::KEY,
            'name'            => 'Agregado y Poda de Métricas',
            'description'     => 'Convierte las muestras de telemetría en resúmenes por hora y borra el detalle vencido.',
            'job_class'       => RollUpDeviceMetricsJob::class,
            'queue'           => 'default',
            'enabled'         => true,
            'schedule_type'   => 'hourly',
            'schedule_config' => json_encode([]),
            'params'          => json_encode([
                'samples_retention_days'  => 14,
                'hourly_retention_months' => 13,
            ]),
            'params_schema'   => json_encode([
                'samples_retention_days' => [
                    'type' => 'integer', 'label' => 'Retención del detalle (días)',
                    'description' => 'Días que se conservan las muestras individuales.',
                    'min' => 1, 'max' => 90, 'required' => true,
                ],
                'hourly_retention_months' => [
                    'type' => 'integer', 'label' => 'Retención de los resúmenes (meses)',
                    'description' => 'Meses que se conservan los agregados horarios.',
                    'min' => 1, 'max' => 60, 'required' => true,
                ],
            ]),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('automation_settings')->where('key', self::KEY)->delete();
    }
};
