<?php

return [
    /*
    | This project has no runtime database consumer. SQLite is retained only
    | as a valid Laravel fallback if framework configuration is inspected.
    */
    'default' => 'sqlite',

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => database_path('database.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
    ],
];
