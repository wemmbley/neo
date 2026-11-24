<?php

declare(strict_types=1);

return [
    'default' => env('DB_CONNECTION', 'sqlite'),

    # Пул соединений. Работает только там, где рантайм требует пул (Swoole): в обычном PHP это
    # ни на что не влияет. Переопределяется для конкретного соединения ключом `pool` внутри него;
    # `'pool' => ['enabled' => false]` отключает пул для соединения.
    'pool' => [
        'min' => (int) env('DB_POOL_MIN', 1),
        'max' => (int) env('DB_POOL_MAX', 10),
        'acquire_timeout' => (float) env('DB_POOL_ACQUIRE_TIMEOUT', 3.0),
        'idle_timeout' => 60.0,
        'max_lifetime' => 3600.0,
        'validate_after' => 30.0,
        'reap_interval' => 30.0, # фоновая чистка простаивающих соединений, секунды; 0 = выключена
    ],

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', base_path('app/Database/Database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
            'timezone' => '+00:00',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'neo'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'timezone' => '+00:00',
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'connection' => env('DB_MIGRATION_CONNECTION'),
        'paths' => [base_path('app/Database/Migrations')],
    ],

    # Какой сидер запускает `db:seed`
    'seeder' => 'App\\Database\\Seeders\\DatabaseSeeder',
];
