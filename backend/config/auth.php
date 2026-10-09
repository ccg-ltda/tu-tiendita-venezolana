<?php

return [
    /*
    | Administrative authentication is intentionally custom and persisted in
    | the admins table. The inert web guard exists only because Laravel's
    | database session handler calls Auth::id() while writing a session.
    */
    'defaults' => [
        'guard' => 'web',
        'passwords' => null,
    ],
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'session_users',
        ],
    ],
    'providers' => [
        // Only required by Laravel's DatabaseSessionHandler. Administrative
        // login does not authenticate through this provider or this guard.
        'session_users' => [
            'driver' => 'database',
            'table' => 'admins',
            'connection' => 'mysql',
        ],
    ],
    'passwords' => [],
    'password_timeout' => 10800,
];
