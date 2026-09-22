<?php

$frontendUrl = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'docs/api', 'docs/api.json'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter([
        $frontendUrl,
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
