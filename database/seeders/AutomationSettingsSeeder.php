<?php

namespace Database\Seeders;

use App\Models\AutomationSetting;
use Illuminate\Database\Seeder;

class AutomationSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $automations = [
            [
                'key'         => 'client_suspension',
                'name'        => 'Suspensión Automática de Clientes',
                'description' => 'Suspende clientes con facturas vencidas tras el periodo de gracia. Intenta un último cobro antes de cortar el servicio.',
                'job_class'   => \App\Jobs\ProcessClientSuspension::class,
                'queue'       => 'suspensions',
                'enabled'     => true,
                'schedule_type'   => 'daily',
                'schedule_config' => ['time' => '02:00'],
                'params'          => ['grace_days' => 3],
                'params_schema'   => [
                    'grace_days' => [
                        'type'        => 'integer',
                        'label'       => 'Días de gracia',
                        'description' => 'Días después del vencimiento antes de suspender',
                        'min'         => 0,
                        'max'         => 30,
                        'required'    => true,
                    ],
                ],
            ],
            [
                'key'         => 'monthly_invoices',
                'name'        => 'Generación Mensual de Facturas',
                'description' => 'Genera automáticamente las facturas recurrentes de todos los clientes activos al inicio del periodo.',
                'job_class'   => \App\Jobs\GenerateMonthlyInvoices::class,
                'queue'       => 'default',
                'enabled'     => true,
                'schedule_type'   => 'monthly',
                'schedule_config' => ['day' => 1, 'time' => '01:00'],
                'params'          => [],
                'params_schema'   => [],
            ],
            [
                'key'         => 'auto_billing',
                'name'        => 'Cobros Automáticos',
                'description' => 'Procesa el cobro automático de facturas próximas a vencer y reintentos sobre facturas fallidas. Centraliza umbrales de reintento, rangos de monto, ventanas de gracia y reglas de notificación.',
                'job_class'   => \App\Jobs\ProcessAutoBilling::class,
                'queue'       => 'default',
                'enabled'     => true,
                'schedule_type'   => 'daily',
                'schedule_config' => ['time' => '02:00'],
                'params'          => [
                    'days_before_due'    => 5,
                    'retry_window_days'  => 7,
                    'max_retries'        => 3,
                    'min_amount'         => 0.50,
                    'max_amount'         => 10000.00,
                    'notify_on_success'  => false,
                    'notify_on_failure'  => true,
                    'notify_on_retry'    => false,
                ],
                'params_schema'   => [
                    'days_before_due' => [
                        'type'        => 'integer',
                        'label'       => 'Anticipación de cobro (días)',
                        'description' => 'Días antes del vencimiento en los que el cobro se intenta automáticamente.',
                        'min'         => 0,
                        'max'         => 30,
                        'required'    => true,
                    ],
                    'retry_window_days' => [
                        'type'        => 'integer',
                        'label'       => 'Ventana de reintento (días)',
                        'description' => 'Días desde la creación durante los cuales una factura fallida es elegible para reintento.',
                        'min'         => 1,
                        'max'         => 30,
                        'required'    => true,
                    ],
                    'max_retries' => [
                        'type'        => 'integer',
                        'label'       => 'Máximo de reintentos por factura',
                        'description' => 'Umbral de intentos fallidos por factura antes de excluirla del ciclo automático.',
                        'min'         => 1,
                        'max'         => 10,
                        'required'    => true,
                    ],
                    'min_amount' => [
                        'type'        => 'decimal',
                        'label'       => 'Monto mínimo por cobro',
                        'description' => 'Las facturas con total menor a este valor se omiten del ciclo automático.',
                        'min'         => 0,
                        'max'         => 100000,
                        'required'    => true,
                    ],
                    'max_amount' => [
                        'type'        => 'decimal',
                        'label'       => 'Monto máximo por cobro',
                        'description' => 'Tope de seguridad. Las facturas con total superior se omiten para evitar cargos atípicos.',
                        'min'         => 0,
                        'max'         => 1000000,
                        'required'    => true,
                    ],
                    'notify_on_success' => [
                        'type'        => 'boolean',
                        'label'       => 'Notificar cobros exitosos',
                        'description' => 'Emite un log informativo en el canal billing por cada cobro exitoso.',
                        'required'    => false,
                    ],
                    'notify_on_failure' => [
                        'type'        => 'boolean',
                        'label'       => 'Notificar cobros fallidos',
                        'description' => 'Emite un log de advertencia en el canal billing por cada cobro fallido.',
                        'required'    => false,
                    ],
                    'notify_on_retry' => [
                        'type'        => 'boolean',
                        'label'       => 'Notificar reintentos exitosos',
                        'description' => 'Emite un log informativo cuando un reintento sobre factura FAILED logra cobrarse.',
                        'required'    => false,
                    ],
                ],
            ],
            [
                'key'         => 'mikrotik_sync',
                'name'        => 'Sincronización MikroTik Queues',
                'description' => 'Sincroniza las Simple Queues de planes y clientes con el router MikroTik para mantener consistencia.',
                'job_class'   => \App\Jobs\SyncMikroTikQueues::class,
                'queue'       => 'default',
                'enabled'     => true,
                'schedule_type'   => 'every_thirty_minutes',
                'schedule_config' => [],
                'params'          => ['cleanup' => false],
                'params_schema'   => [
                    'cleanup' => [
                        'type'        => 'boolean',
                        'label'       => 'Limpieza de colas huérfanas',
                        'description' => 'Elimina colas que no existan en la base de datos',
                        'required'    => false,
                    ],
                ],
            ],
        ];

        foreach ($automations as $automation) {
            AutomationSetting::updateOrCreate(
                ['key' => $automation['key']],
                $automation
            );
        }

        AutomationSetting::flushCache();
    }
}
