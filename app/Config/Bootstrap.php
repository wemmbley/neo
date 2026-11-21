<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);

return [
    'base_path' => $base,

    'autoload' => [
        'classes' => [
            $base . '/src' => 'Neo',
            $base . '/app' => 'App',
        ],

        'files' => [
            $base . '/src/Kernel/helpers.php',
            $base . '/src/Config/helpers.php',
        ],
    ],
];
