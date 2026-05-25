<?php

/*
|--------------------------------------------------------------------------
| Configuración del módulo de notificaciones del sistema
|--------------------------------------------------------------------------
|
| Este archivo declara los canales disponibles, el mapeo severidad → destinatarios
| y los parámetros transversales (cola, deduplicación, reintentos). Modificarlo
| permite habilitar/inhabilitar canales o agregar nuevos sin tocar el dispatcher.
|
*/

return [

    'enabled' => env('NOTIFICATIONS_ENABLED', true),

    'queue' => [
        // Si no se define explícitamente, se hereda la conexión por defecto de
        // Laravel (config('queue.default')). En tests eso es 'sync', en
        // producción será 'database' u otra. Definir aquí solo si el módulo de
        // notificaciones debe usar una conexión distinta a la del resto de la app.
        'connection' => env('NOTIFICATIONS_QUEUE_CONNECTION'),
        'name'       => env('NOTIFICATIONS_QUEUE_NAME', 'notifications'),
    ],

    'retry' => [
        'max_attempts'    => 5,
        'backoff_seconds' => [10, 30, 90, 270, 600],
    ],

    'deduplication' => [
        'store'               => env('NOTIFICATIONS_DEDUP_STORE', env('CACHE_STORE', 'database')),
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
    | Canales disponibles (Strategy)
    |--------------------------------------------------------------------------
    |
    | Cada canal debe implementar App\Notifications\Core\Contracts\NotificationChannel.
    | Para agregar un nuevo canal (email, slack, sms, ...), basta con crear la clase
    | y registrarla aquí — el dispatcher la descubre dinámicamente.
    |
    */
    'channels' => [
        'telegram' => [
            'driver'  => \App\Notifications\Channels\Telegram\TelegramChannel::class,
            'enabled' => env('TELEGRAM_ENABLED', true),
            'config'  => [
                'bot_token' => env('TELEGRAM_BOT_TOKEN'),
                'base_url'  => env('TELEGRAM_BASE_URL', 'https://api.telegram.org'),
                'timeout'   => (int) env('TELEGRAM_TIMEOUT', 10),
                'parse_mode'=> env('TELEGRAM_PARSE_MODE', 'MarkdownV2'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ruteo por severidad
    |--------------------------------------------------------------------------
    |
    | Para cada severidad se define una lista de destinos {channel => address}.
    | Una notificación puede entregarse a múltiples destinos simultáneamente.
    |
    */
    'severity_routes' => [
        'critical' => [
            ['channel' => 'telegram', 'address' => env('TELEGRAM_CHAT_CRITICAL')],
        ],
        'summary' => [
            ['channel' => 'telegram', 'address' => env('TELEGRAM_CHAT_SUMMARY')],
        ],
        'info' => [
            ['channel' => 'telegram', 'address' => env('TELEGRAM_CHAT_INFO')],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Overrides por categoría
    |--------------------------------------------------------------------------
    |
    | Permite enrutar categorías específicas a destinos distintos a los de su
    | severidad. Por ejemplo, podríamos enviar todos los WORKER_FAILURE al chat
    | crítico aunque su severidad base sea SUMMARY.
    |
    */
    'category_overrides' => [
        // 'worker_failure' => [
        //     ['channel' => 'telegram', 'address' => env('TELEGRAM_CHAT_CRITICAL')],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoreo MikroTik
    |--------------------------------------------------------------------------
    */
    'mikrotik_monitor' => [
        'enabled'               => env('NOTIFICATIONS_MIKROTIK_MONITOR', true),
        'consecutive_failures'  => (int) env('MIKROTIK_FAILURE_THRESHOLD', 2),
        'health_check_timeout'  => (int) env('MIKROTIK_HEALTH_TIMEOUT', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Meta-fallo
    |--------------------------------------------------------------------------
    |
    | Cuando un envío falla en todos sus reintentos (status=exhausted) el
    | dispatcher emite una notificación CRITICAL sobre el meta-fallo. Para
    | evitar recursión infinita, esta meta-alerta solo se loguea si tampoco
    | puede entregarse.
    |
    */
    'meta_failure_notification' => true,
];
