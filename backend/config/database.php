<?php

return [
    /* MySQL/MariaDB is the application's runtime source of truth. */
    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            // Solo toca la constante PDO::MYSQL_ATTR_SSL_CA (deprecated en PHP 8.5)
            // cuando realmente hay un CA configurado, para no emitir el warning
            // en cada request cuando no se usa SSL (ej. desarrollo local).
            'options' => extension_loaded('pdo_mysql') && env('MYSQL_ATTR_SSL_CA')
                ? [PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA')]
                : [],
        ],
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => database_path('database.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
    ],
];
