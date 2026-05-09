<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    // Permitir todos los orígenes
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Content-Type',
        'X-Requested-With',
        'Authorization',
        'Accept',
        'Origin',
    ],

    'allowed_methods' => ['*'],

    // Set to true only if you are using cookies / Authorization header cross-site
    'supports_credentials' => false,

    'exposed_headers' => [],

    'max_age' => 0,
];
