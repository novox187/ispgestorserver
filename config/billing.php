<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Días de gracia
    |--------------------------------------------------------------------------
    | Días después del vencimiento de una factura antes de proceder con la
    | suspensión automática del servicio del cliente.
    */
    'suspension_grace_days' => (int) env('BILLING_SUSPENSION_GRACE_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Timezone del scheduler
    |--------------------------------------------------------------------------
    */
    'timezone' => env('BILLING_TIMEZONE', 'America/Bogota'),

    /*
    |--------------------------------------------------------------------------
    | Horarios del scheduler (formato 'HH:MM')
    |--------------------------------------------------------------------------
    */
    'schedule' => [
        'generate_invoices_day'  => (int) env('BILLING_SCHEDULE_INVOICE_DAY', 1),
        'generate_invoices_time' => env('BILLING_SCHEDULE_INVOICE_TIME', '00:05'),
        'process_payments_time'  => env('BILLING_SCHEDULE_PAYMENTS_TIME', '02:00'),
        'auto_suspend_time'      => env('BILLING_SCHEDULE_SUSPEND_TIME', '08:00'),
        'auto_reactivate_time'   => env('BILLING_SCHEDULE_REACTIVATE_TIME', '10:00'),
        'mikrotik_sync_hours'    => [
            (int) env('BILLING_SCHEDULE_MKSYNC_HOUR1', 6),
            (int) env('BILLING_SCHEDULE_MKSYNC_HOUR2', 18),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Nombres de las queues
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'suspensions'   => env('BILLING_QUEUE_SUSPENSIONS', 'suspensions'),
        'reactivations' => env('BILLING_QUEUE_REACTIVATIONS', 'reactivations'),
    ],
];
