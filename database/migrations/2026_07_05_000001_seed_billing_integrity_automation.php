<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inserta el registro 'billing_integrity' en automation_settings para que la
 * conciliación de integridad quede gestionada por el módulo de Workers
 * Automáticos. Idempotente: si ya existe, no toca nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('automation_settings')) {
            return;
        }

        $exists = DB::table('automation_settings')->where('key', 'billing_integrity')->exists();
        if ($exists) {
            return;
        }

        DB::table('automation_settings')->insert([
            'key'             => 'billing_integrity',
            'name'            => 'Conciliación de Integridad',
            'description'     => 'Verifica (solo lectura) los invariantes entre facturación, cortes de servicio y MikroTik: estados inconsistentes con las ventanas de corte, facturas emitidas durante un corte, planes activos en clientes cortados y desalineación con la lista morosos del router. Notifica los hallazgos sin corregirlos.',
            'job_class'       => \App\Jobs\ReconcileBillingIntegrity::class,
            'queue'           => 'default',
            'enabled'         => true,
            'schedule_type'   => 'daily',
            'schedule_config' => json_encode(['time' => '03:00']),
            'params'          => json_encode([
                'check_mikrotik' => true,
            ]),
            'params_schema'   => json_encode([
                'check_mikrotik' => [
                    'type' => 'boolean', 'label' => 'Verificar lista morosos en MikroTik',
                    'description' => 'Compara los clientes suspendidos en BD contra la address-list morosos del router. Si el router no responde, el chequeo se omite sin marcar error.',
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
            DB::table('automation_settings')->where('key', 'billing_integrity')->delete();
        }
    }
};
