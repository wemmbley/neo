<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);

return [
    'base_path' => $base,

    'autoload' => [

        'loader'  => $base . '/neo/Autoload/Autoload.php',

        'classes' => [
            $base . '/neo' => 'Neo',
            $base . '/app' => 'App',
        ],

        'files' => [
            $base . '/neo/Kernel/helpers.php',
            $base . '/neo/Config/helpers.php',
        ],
    ],
];
