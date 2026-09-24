<?php

return [
    'default' => env('QUEUE_CONNECTION', 'sync'),

    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],
        'null' => [
            'driver' => 'null',
        ],
    ],

    'failed' => [
        'driver' => 'null',
    ],
];
