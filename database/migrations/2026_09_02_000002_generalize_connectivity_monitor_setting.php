<?php

use App\Jobs\MonitorDeviceConnectivityJob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Apunta el worker de conectividad al job generalizado.
 *
 * El monitoreo dejó de ser exclusivo de MikroTik: ahora recorre el inventario
 * entero y delega en el `DeviceDriver` de cada equipo. La fila de
 * `automation_settings` guarda el FQCN del job y la clave con la que el propio
 * job lee sus parámetros, así que ambos tienen que moverse a la vez.
 *
 * Los parámetros (`consecutive_failures_threshold`, `health_check_timeout_seconds`)
 * y la planificación se conservan intactos: lo que el cliente tenga ajustado en
 * el panel sigue valiendo.
 */
return new class extends Migration
{
    private const OLD_KEY = 'mikrotik_connectivity_monitor';
    private const NEW_KEY = 'device_connectivity_monitor';

    public function up(): void
    {
        DB::table('automation_settings')
            ->where('key', self::OLD_KEY)
            ->update([
                'key'         => self::NEW_KEY,
                'job_class'   => MonitorDeviceConnectivityJob::class,
                'name'        => 'Monitor de Conectividad de Dispositivos',
                'description' => 'Verifica periódicamente la conectividad con cada equipo activo del inventario '
                    . '(routers MikroTik y antenas Ubiquiti). Tras N fallos consecutivos emite una alerta CRITICAL '
                    . 'al canal correspondiente; al recuperarse emite un INFO.',
                'updated_at'  => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('automation_settings')
            ->where('key', self::NEW_KEY)
            ->update([
                'key'         => self::OLD_KEY,
                'job_class'   => \App\Jobs\MonitorMikrotikConnectivityJob::class,
                'name'        => 'Monitor de Conectividad MikroTik',
                'description' => 'Verifica periódicamente la conectividad con cada router MikroTik activo. '
                    . 'Tras N fallos consecutivos emite una alerta CRITICAL al canal correspondiente; '
                    . 'al recuperarse emite un INFO.',
                'updated_at'  => now(),
            ]);
    }
};
