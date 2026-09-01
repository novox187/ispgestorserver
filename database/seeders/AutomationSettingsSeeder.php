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
            [
                'key'         => 'mikrotik_connectivity_monitor',
                'name'        => 'Monitor de Conectividad MikroTik',
                'description' => 'Verifica periódicamente la conectividad con cada router MikroTik activo. Tras N fallos consecutivos emite una alerta CRITICAL al canal correspondiente; al recuperarse emite un INFO.',
                'job_class'   => \App\Jobs\MonitorMikrotikConnectivityJob::class,
                'queue'       => 'default',
                'enabled'     => true,
                'schedule_type'   => 'every_five_minutes',
                'schedule_config' => [],
                'params'          => [
                    'consecutive_failures_threshold' => 2,
                    'health_check_timeout_seconds'   => 3,
                ],
                'params_schema'   => [
                    'consecutive_failures_threshold' => [
                        'type'        => 'integer',
                        'label'       => 'Fallos consecutivos para alertar',
                        'description' => 'Número de chequeos fallidos seguidos antes de marcar el router como desconectado y emitir alerta. Evita falsos positivos por timeouts puntuales.',
                        'min'         => 1,
                        'max'         => 10,
                        'required'    => true,
                    ],
                    'health_check_timeout_seconds' => [
                        'type'        => 'integer',
                        'label'       => 'Timeout del chequeo (segundos)',
                        'description' => 'Tiempo máximo de espera por chequeo individual contra el router. Si el RouterOS no responde en este lapso, el chequeo cuenta como fallo.',
                        'min'         => 1,
                        'max'         => 30,
                        'required'    => true,
                    ],
                ],
            ],

            /*
             * Aprovisionamiento automático de dispositivos.
             *
             * No tiene `job_class` con planificación propia: el flujo lo
             * disparan los agentes al detectar un equipo, no el scheduler. La
             * fila existe para que sus parámetros operativos sean editables
             * desde el panel sin redesplegar en Coolify — que fue el criterio
             * que ya siguieron los módulos de MikroTik y notificaciones.
             */
            [
                'key'         => 'device_auto_provisioning',
                'name'        => 'Alta Automática de Dispositivos',
                'description' => 'Gobierna el flujo que detecta un router conectado por cable, le monta un túnel WireGuard en ambos extremos, verifica el enlace y lo registra. Desactivarlo detiene las altas nuevas sin revocar credenciales de los agentes.',
                'job_class'   => \App\Jobs\ExpireStaleProvisioningTasks::class,
                'queue'       => 'provisioning',
                'enabled'     => true,
                // El vigilante de tareas vencidas sí corre en bucle: es lo que
                // rescata una sesión cuyo agente murió a mitad de aplicar.
                'schedule_type'   => 'every_five_minutes',
                'schedule_config' => [],
                'params'          => [
                    'auto_approve'         => true,
                    'vpn_subnet'           => '10.77.0.0/24',
                    'vpn_server_ip'        => '10.77.0.1',
                    'endpoint_host'        => '',
                    'endpoint_port'        => 51820,
                    'keepalive'            => 25,
                ],
                'params_schema'   => [
                    'auto_approve' => [
                        'type'        => 'boolean',
                        'label'       => 'Aprobar altas automáticamente',
                        'description' => 'Con esto activo, un equipo compatible se da de alta sin intervención. Desactívalo para que cada alta espere aprobación manual en el panel antes de tocar el router.',
                        'required'    => true,
                    ],
                    'vpn_subnet' => [
                        'type'        => 'string',
                        'label'       => 'Subred de la VPN (CIDR)',
                        'description' => 'Rango del que se reparten las direcciones de gestión de los routers. Ej: 10.77.0.0/24. Cambiarlo no reconfigura los equipos ya dados de alta.',
                        'required'    => true,
                    ],
                    'vpn_server_ip' => [
                        'type'        => 'string',
                        'label'       => 'IP del servidor en la VPN',
                        'description' => 'Dirección del extremo del hosting dentro de la subred. Es la que los routers hacen ping para verificar el túnel.',
                        'required'    => true,
                    ],
                    'endpoint_host' => [
                        'type'        => 'string',
                        'label'       => 'Host público del servidor VPN',
                        'description' => 'Dominio o IP a la que marcan los routers. Déjalo vacío para usar el que publique el propio agente del hosting; rellénalo solo si el host ve una IP privada y los equipos deben marcar a otro nombre.',
                        'required'    => false,
                    ],
                    'endpoint_port' => [
                        'type'        => 'integer',
                        'label'       => 'Puerto del servidor VPN',
                        'description' => 'Puerto UDP en el que escucha WireGuard en el hosting.',
                        'min'         => 1,
                        'max'         => 65535,
                        'required'    => true,
                    ],
                    'keepalive' => [
                        'type'        => 'integer',
                        'label'       => 'Keepalive (segundos)',
                        'description' => 'Cada cuánto envía tráfico el router para mantener abierto el mapeo NAT de su oficina. Sin esto, el hosting deja de poder iniciar conexiones hacia el equipo.',
                        'min'         => 0,
                        'max'         => 300,
                        'required'    => true,
                    ],
                ],
            ],

            [
                'key'         => 'provisioning_agent_monitor',
                'name'        => 'Monitor de Agentes de Aprovisionamiento',
                'description' => 'Vigila que los agentes sigan reportando. Un agente caído no rompe nada de forma visible: simplemente deja de haber altas, y por eso hay que detectarlo activamente.',
                'job_class'   => \App\Jobs\MonitorProvisioningAgentsJob::class,
                'queue'       => 'default',
                'enabled'     => true,
                'schedule_type'   => 'every_five_minutes',
                'schedule_config' => [],
                'params'          => [
                    'offline_after_minutes' => 5,
                ],
                'params_schema'   => [
                    'offline_after_minutes' => [
                        'type'        => 'integer',
                        'label'       => 'Minutos sin reportar para alertar',
                        'description' => 'Tiempo sin latido tras el cual se considera caído a un agente. Debe ser holgadamente mayor que su intervalo de sondeo.',
                        'min'         => 1,
                        'max'         => 120,
                        'required'    => true,
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
