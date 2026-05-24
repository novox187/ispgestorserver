<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inserta el registro 'auto_billing' en automation_settings para que la
 * automatización de cobros quede gestionada exclusivamente por el módulo
 * de Workers Automáticos. Idempotente: si ya existe, no toca nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('automation_settings')) {
            return;
        }

        $exists = DB::table('automation_settings')->where('key', 'auto_billing')->exists();
        if ($exists) {
            return;
        }

        DB::table('automation_settings')->insert([
            'key'             => 'auto_billing',
            'name'            => 'Cobros Automáticos',
            'description'     => 'Procesa el cobro automático de facturas próximas a vencer y reintentos sobre facturas fallidas. Centraliza umbrales de reintento, rangos de monto, ventanas de gracia y reglas de notificación.',
            'job_class'       => \App\Jobs\ProcessAutoBilling::class,
            'queue'           => 'default',
            'enabled'         => true,
            'schedule_type'   => 'daily',
            'schedule_config' => json_encode(['time' => '02:00']),
            'params'          => json_encode([
                'days_before_due'    => 5,
                'retry_window_days'  => 7,
                'max_retries'        => 3,
                'min_amount'         => 0.50,
                'max_amount'         => 10000.00,
                'notify_on_success'  => false,
                'notify_on_failure'  => true,
                'notify_on_retry'    => false,
            ]),
            'params_schema'   => json_encode([
                'days_before_due' => [
                    'type' => 'integer', 'label' => 'Anticipación de cobro (días)',
                    'description' => 'Días antes del vencimiento en los que el cobro se intenta automáticamente.',
                    'min' => 0, 'max' => 30, 'required' => true,
                ],
                'retry_window_days' => [
                    'type' => 'integer', 'label' => 'Ventana de reintento (días)',
                    'description' => 'Días desde la creación durante los cuales una factura fallida es elegible para reintento.',
                    'min' => 1, 'max' => 30, 'required' => true,
                ],
                'max_retries' => [
                    'type' => 'integer', 'label' => 'Máximo de reintentos por factura',
                    'description' => 'Umbral de intentos fallidos por factura antes de excluirla del ciclo automático.',
                    'min' => 1, 'max' => 10, 'required' => true,
                ],
                'min_amount' => [
                    'type' => 'decimal', 'label' => 'Monto mínimo por cobro',
                    'description' => 'Las facturas con total menor a este valor se omiten del ciclo automático.',
                    'min' => 0, 'max' => 100000, 'required' => true,
                ],
                'max_amount' => [
                    'type' => 'decimal', 'label' => 'Monto máximo por cobro',
                    'description' => 'Tope de seguridad. Las facturas con total superior se omiten para evitar cargos atípicos.',
                    'min' => 0, 'max' => 1000000, 'required' => true,
                ],
                'notify_on_success' => [
                    'type' => 'boolean', 'label' => 'Notificar cobros exitosos',
                    'description' => 'Emite un log informativo en el canal billing por cada cobro exitoso.',
                    'required' => false,
                ],
                'notify_on_failure' => [
                    'type' => 'boolean', 'label' => 'Notificar cobros fallidos',
                    'description' => 'Emite un log de advertencia en el canal billing por cada cobro fallido.',
                    'required' => false,
                ],
                'notify_on_retry' => [
                    'type' => 'boolean', 'label' => 'Notificar reintentos exitosos',
                    'description' => 'Emite un log informativo cuando un reintento sobre factura FAILED logra cobrarse.',
                    'required' => false,
                ],
            ]),
            'last_run_at'     => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('automation_settings')) {
            DB::table('automation_settings')->where('key', 'auto_billing')->delete();
        }
    }
};
