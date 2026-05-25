<?php

/*
|--------------------------------------------------------------------------
| Configuración del módulo de notificaciones del sistema
|--------------------------------------------------------------------------
|
| IMPORTANTE: este archivo **NO** contiene parámetros del canal (bot tokens,
| chat IDs, parse modes, addresses, etc.). Todos esos valores viven en la
| base de datos:
|
|   - notification_channel_configs (credenciales y settings por canal)
|   - notification_event_routes    (rutas por categoría → canal/dirección)
|
| Aquí solo declaramos:
|   - el **registro de drivers** disponibles (clases que implementan
|     NotificationChannel), análogo a `config/queue.php` o `config/mail.php`.
|   - parámetros estructurales del módulo (cola, reintentos, dedup TTLs,
|     umbrales del monitor MikroTik) que son código operativo, no
|     configuración de notificación expuesta al admin.
|
| Para agregar un nuevo canal: implementar NotificationChannel y registrar
| la clase en `channels`. Las credenciales se administran desde el panel.
|
*/

return [

    'queue' => [
        // Hereda la conexión por defecto de Laravel (config('queue.default')).
        // En tests vale 'sync', en producción 'database'. No exponemos esto a env
        // del módulo de notificaciones para evitar configuraciones divergentes.
        'connection' => null,
        'name'       => 'notifications',
    ],

    'retry' => [
        'max_attempts'    => 5,
        'backoff_seconds' => [10, 30, 90, 270, 600],
    ],

    'deduplication' => [
        // null → usa el store por defecto de Laravel (config('cache.default')).
        'store'               => null,
        'default_ttl_seconds' => 300,
        'per_category'        => [
            'mikrotik_connectivity' => 600,
            'mikrotik_recovery'     => 300,
            'worker_summary'        => 60,
            'worker_failure'        => 300,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Registro de drivers de canal (Strategy)
    |--------------------------------------------------------------------------
    |
    | Solo declaramos la clase del driver. La habilitación, credenciales y
    | settings de cada canal se administran 100% desde el panel y se almacenan
    | en `notification_channel_configs`.
    |
    */
    'channels' => [
        'telegram' => [
            'driver' => \App\Notifications\Channels\Telegram\TelegramChannel::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoreo MikroTik
    |--------------------------------------------------------------------------
    |
    | Umbrales operativos del job de health-check. No son parámetros del canal
    | ni del envío; son código de comportamiento del monitor.
    |
    */
    'mikrotik_monitor' => [
        'enabled'              => true,
        'consecutive_failures' => 2,
        'health_check_timeout' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Meta-fallo
    |--------------------------------------------------------------------------
    */
    'meta_failure_notification' => true,
];
