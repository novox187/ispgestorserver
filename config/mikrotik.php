<?php

return [
    'enabled' => (bool) env('MIKROTIK_ENABLED', true),
    'host' => env('MIKROTIK_HOST', '10.0.0.2'),
    'user' => env('MIKROTIK_USER', 'laravel_user'),
    'pass' => env('MIKROTIK_PASS', 'Erty3216'),
    'port' => (int) env('MIKROTIK_PORT', 8728),
    'timeout' => (int) env('MIKROTIK_TIMEOUT', 2),
    'attempts' => (int) env('MIKROTIK_ATTEMPTS', 1),
    'delay' => (int) env('MIKROTIK_DELAY', 0),
];
