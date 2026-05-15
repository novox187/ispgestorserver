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
