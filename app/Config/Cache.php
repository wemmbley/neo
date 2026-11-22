<?php

return [
    'default' => 'file',

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