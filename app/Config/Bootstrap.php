<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);

return [
    'base_path' => $base,

    'env' => $base . '/.env',

    'requires' => [
        'php >= 8.5',
        'php_ext' => [
            'curl',       # HTTP клиент
            'pdo_mysql',  # Дополнительный панет для моделей
            'pdo_sqlite', # Базовый пакет для моделей
            'sockets',    # UDP клиент и другие протоколы
            'zip',        # скачивание и распаковка пакетов с гита
            'mbstring',   # mb_* в командах, кириллица
            'json',       # config:show, вывод конфигов
            'pcre',       # разбор Env и сигнатур команд
            'spl',        # spl_autoload_register
            'Reflection', # автосборка зависимостей контейнером
            'openssl',    # crypt
        ],
    ],

    'autoload' => [

        'loader'  => $base . '/neo/Foundation/Autoload/Autoload.php',

        'requirements' => $base . '/neo/Foundation/Kernel/Requirements.php',

        'classes' => [
            $base . '/neo' => 'Neo',
            $base . '/app' => 'App',
        ],

        'files' => [
            $base . '/neo/Foundation/Kernel/helpers.php',
            $base . '/neo/Foundation/Config/helpers.php',
            $base . '/neo/Diagnostic/Debug/Helpers.php',
            $base . '/neo/Diagnostic/Testing/Helpers.php',
            $base . '/neo/Information/Lang/Helpers.php',
            $base . '/neo/Information/Collection/Helpers.php',
            $base . '/neo/Network/Http/Helpers.php',
            $base . '/neo/Presentation/View/Helpers.php',
        ],
    ],
];
