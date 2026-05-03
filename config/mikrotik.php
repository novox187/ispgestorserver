<?php

return [
    'host' => env('MIKROTIK_HOST', '10.0.0.2'),
    'user' => env('MIKROTIK_USER', 'laravel_user'),
    'pass' => env('MIKROTIK_PASS', 'Erty3216'),
    'port' => (int) env('MIKROTIK_PORT', 8728),
    'timeout' => (int) env('MIKROTIK_TIMEOUT', 10),
    'attempts' => (int) env('MIKROTIK_ATTEMPTS', 10),
    'delay' => (int) env('MIKROTIK_DELAY', 1),
];