<?php

return [

    'default' => 'daily',

    'drivers' => [

        'single' => [
            'driver' => 'single',
            'path' => base_path('app/Storage/Logs/neo.log')
        ],

        'daily'  => [
            'driver' => 'daily',
            'path' => base_path('app/Storage/Logs/neo.log'),
            'level' => 'info'
        ],

        'all'    => [
            'driver' => 'stack',
            'drivers' => ['single', 'daily']
        ],

        'off'    => [
            'driver' => 'disabled'
        ],

    ],

    'channels' => [

        'file'     => [
            'channel' => 'file',
            'driver' => 'daily'
        ],

        # Future...
//        'telegram' => [
//            'channel' => 'telegram',
//            'token' => '...',
//            'chat_id' => '...'
//        ],
    ],
];