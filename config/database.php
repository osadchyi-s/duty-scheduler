<?php

use Illuminate\Support\Str;

return [
    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', 'db'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'wfm_duty_matrix'),
            'username' => env('DB_USERNAME', 'wfm'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],
    ],

    'migrations' => 'migrations',

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'default' => [
            'host' => env('REDIS_HOST', 'redis'),
            'password' => env('REDIS_PASSWORD', null),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
        'cache' => [
            'host' => env('REDIS_HOST', 'redis'),
            'password' => env('REDIS_PASSWORD', null),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
    ],
];
