<?php

declare(strict_types=1);

return [
    #
    # Default Session Driver
    #
    'driver' => env('SESSION_DRIVER', 'database'),

    #
    # Session Lifetime
    #
    'lifetime' => (int) env('SESSION_LIFETIME', 120),
    'expire_on_close' => env('SESSION_EXPIRE_ON_CLOSE', false),

    #
    # Session Encryption
    #
    'encrypt' => env('SESSION_ENCRYPT', false),

    #
    # Session File Location
    #
    'files' => base_path('app/Storage/Sessions'),

    #
    # Session Database Connection
    #
    'connection' => env('SESSION_CONNECTION'),

    #
    # Session Database Table
    #
    'table' => env('SESSION_TABLE', 'sessions'),

    #
    # Экономия записей в хранилище (важно для sqlite и долгоживущих серверов).
    # touch_interval: 0 = писать сессию на каждый запрос; N = не чаще раза в N секунд, пока данные не менялись.
    # lazy: новая сессия без данных (просто просмотр страницы) не сохраняется и куку не выдаёт.
    #
    'touch_interval' => (int) env('SESSION_TOUCH_INTERVAL', 60),
    'lazy' => env('SESSION_LAZY', true),

    #
    # Session Sweeping Lottery
    #
    'lottery' => [2, 100],

    #
    # Session Cookie
    #
    'cookie' => env('SESSION_COOKIE', 'neo_session'),

    'path' => env('SESSION_PATH', '/'),
    'domain' => env('SESSION_DOMAIN'),
    'secure' => env('SESSION_SECURE_COOKIE'),
    'http_only' => env('SESSION_HTTP_ONLY', true),
    'same_site' => env('SESSION_SAME_SITE', 'lax'),
    'partitioned' => env('SESSION_PARTITIONED_COOKIE', false),

    #
    # Session Serialization
    #
    'serialization' => 'json',
];
