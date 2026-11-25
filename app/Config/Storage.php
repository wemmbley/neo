<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        'local'  => [
            'driver' => 'local',
            'root' => base_path('app/Storage/Private'),
            'serve' => true,
            'throw' => false,
        ],

        'public'  => [
            'driver' => 'local',
            'root' => base_path('app/Storage/Public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        'memory' => [
            'driver' => 'memory',
        ],
    ],

    'links' => [
        base_path('app/Storage/Public') => base_path('public/Storage/Public'),
        base_path('src/UI/Resources') => base_path('public/Resources'),
    ],
];