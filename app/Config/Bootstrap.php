<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);

return [
    'base_path' => $base,

    'env' => $base . '/.env',

    'autoload' => [

        'loader'  => $base . '/neo/Foundation/Autoload/Autoload.php',

        'classes' => [
            $base . '/neo' => 'Neo',
            $base . '/app' => 'App',
        ],

        'files' => [
            $base . '/neo/Foundation/Kernel/helpers.php',
            $base . '/neo/Foundation/Config/helpers.php',
            $base . '/neo/Foundation/Debug/helpers.php',
            $base . '/neo/Foundation/Testing/helpers.php',
        ],
    ],
];
