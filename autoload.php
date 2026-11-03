<?php

return [

    # |
    # | Пространства имён классов которые будут загружены в ОЗУ.
    # |
    'namespaces' => [
        __DIR__ . '/neo' => 'Neo',
        __DIR__ . '/src' => 'Src',
        __DIR__ . '/app' => 'App',
    ],

    # |
    # | Загружаемые файлы в ОЗУ.
    # |
    'files' => [
        __DIR__ . '/neo/Debug/Helpers.php',
        __DIR__ . '/neo/Kernel/Helpers.php',
        __DIR__ . '/neo/Config/Helpers.php',
        __DIR__ . '/neo/Testing/Helpers.php',
        __DIR__ . '/neo/Lang/Helpers.php',
    ],

    # |
    # | Папки загружаемых языков.
    # |
    'langs' => [
        'validation' => __DIR__ . '/neo/Validator/Lang',
    ],

    # |
    # | Провайдеры, которые будут загружены в Container.
    # |
    'providers' => [

    ],

    # |
    # | Файлы команд для cli.
    # |
    'commands' => [
        \Neo\Console\Commands\HelpCommand::class,
        \Neo\Testing\Commands\RunTestsCommand::class,
    ],

    # |
    # | Папки с тестами. Запускаются при php cli test
    # |
    'tests_paths' => [
        __DIR__ . '/neo/Kernel/Tests',
    ],

];