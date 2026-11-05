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
    # | Провайдеры, которые будут загружены в Container.
    # |
    'providers' => [
        \Neo\Logger\Providers\LoggerServiceProvider::class,
        \Neo\Storage\Providers\StorageServiceProvider::class,
    ],

    # |
    # | Доступные хранилища для доступа.
    # | Нужны только для Storage::
    # |
    'storage' => [
        'default' => 'local',
        'disks' => [
            'local' => [
                'driver' => 'local',
                'root' => __DIR__ . '/app/Storage',
                'serve' => true,
                'throw' => true,
                'report' => true,
            ],
            'memory' => [
                'driver' => 'memory'
            ],
        ],
        'links' => [
            'public/storage' => 'app/Storage',
        ],
    ],

    # |
    # | Доступные каналы логгирования.
    # | Нужны только для Log::
    # |
    'reporting' => [
        'default'  => 'daily',
        'drivers'  => [
            'single'  => ['driver' => 'single',   'path' => __DIR__ . '/app/Logs/app.log'],
            'daily'   => ['driver' => 'daily',    'path' => __DIR__ . '/app/Logs/app.log', 'level' => 'info'],
            'all'     => ['driver' => 'stack',    'drivers' => ['single', 'daily']],
            'off'     => ['driver' => 'disabled'],
        ],
        'channels' => [
            'file'     => ['channel' => 'file', 'driver' => 'daily'],
            'telegram' => ['channel' => 'telegram', 'token' => '...', 'chat_id' => '...'],
        ],
    ],

    # Настройка кэша, если будет использоваться.
    # Нужен для Cache::
    #
    'cache' => [
        'default' => 'file',
        'prefix'  => 'neo',
        'stores'  => [
            'array' => ['driver' => 'array'],
            'file'  => [
                'driver' => 'file',
                'disk' => 'local',
                'directory' => 'cache'
            ],
        ],
    ],

    # |
    # | Автоматически регистрируемые Фасады.
    # | Нужны только чтобы избавиться от use.
    # | Сам по себе пункт не обязателен.
    # |
    'facades' => [
        \Neo\Storage\Storage::class,
        \Neo\Logger\Log::class,
        \Neo\Cache\Cache::class,
    ],

    # |
    # | Папки загружаемых языков.
    # | Нужны только для __()
    # |
    'langs' => [
        'validation' => __DIR__ . '/neo/Validator/Lang',
    ],

    # |
    # | Файлы команд для cli.
    # | Нужны только для php cli в консоли.
    # |
    'commands' => [
        \Neo\Console\Commands\HelpCommand::class,
        \Neo\Testing\Commands\RunTestsCommand::class,
    ],

    # |
    # | Папки с тестами. Запускаются при php cli test
    # | Нужны только при запуске php cli test из консоли.
    # |
    'tests_paths' => [
        __DIR__ . '/neo/Kernel/Tests',
        __DIR__ . '/neo/Lang/Tests',
        __DIR__ . '/neo/Console/Tests',
        __DIR__ . '/neo/Config/Tests',
        __DIR__ . '/neo/Autoload/Tests',
        __DIR__ . '/neo/Transfer/Tests',
        __DIR__ . '/neo/Validator/Tests',
        __DIR__ . '/neo/Datetime/Tests',
        __DIR__ . '/neo/Storage/Tests',
        __DIR__ . '/neo/Logger/Tests',
        __DIR__ . '/neo/Cache/Tests',
    ],

];