<?php

declare(strict_types=1);

# Конфиг пакета по умолчанию. Значения из app/Config/database.php сильнее.
return [
    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', base_path('app/Database/database.sqlite')),
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
    'seeder' => 'Database\\Seeders\\DatabaseSeeder',
];
