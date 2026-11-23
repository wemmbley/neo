<?php

return [
    'default' => env('CACHE_STORE', 'file'),

    'prefix'  => 'neo',

    'stores'  => [

        'array' => [
            'driver' => 'array'
        ],

        'file'  => [
            'driver' => 'file',
            'disk' => 'local',
            'directory' => 'cache'
        ],

    ],
];