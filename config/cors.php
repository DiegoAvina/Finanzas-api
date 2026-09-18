<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Aquí se configuran los orígenes permitidos para consumir la API.
    | En producción, define CORS_ALLOWED_ORIGINS en el .env con la(s)
    | URL(s) reales del frontend, separadas por coma (sin espacios).
    | Ejemplo: CORS_ALLOWED_ORIGINS=https://app.midominio.com
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', ''))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
