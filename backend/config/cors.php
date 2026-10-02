<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | El frontend (https://tutienditavenezolana.com) consume esta API desde
    | un subdominio distinto (https://api.tutienditavenezolana.com) usando
    | fetch con credentials: 'include'. Los navegadores prohíben combinar
    | credentials: 'include' con Access-Control-Allow-Origin: '*', por lo
    | que el origen permitido debe especificarse explícitamente.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:5173')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
