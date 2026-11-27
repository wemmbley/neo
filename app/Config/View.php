<?php

return [

    #
    # Базовая папка с шаблонами без пространства имён.
    #
    'basic_path' => base_path('src/UI/Views'),

    #
    # Пространство имён для разделения папок.
    #
    'namespaces' => [
        // 'namespace' => __DIR__ . '/folder/views',
    ],

    #
    # Полная настройка кэширования.
    #
    'cache' => [

        #
        # Включает кэш по умолчанию. Можно подставить сразу env().
        #
        'enabled' => env('CACHE_ENABLED', false),

        #
        # true: перед каждым рендером проверять, не изменился ли файл шаблона.
        # false: проверять один раз за жизнь процесса, без обращений к диску на каждый рендер.
        # Для production и `serve --swoole` ставьте false. После правки шаблона перезапустите сервер.
        #
        'validate' => env('VIEW_VALIDATE', true),

        #
        # Эта опция кэширует ВСЕ шаблоны без исключения.
        # С ней директива @cache становится бесмысленной.
        #
        'strategy' => 'full', # full|directive

        #
        # Директория для кэширования шаблонов. Ускоряет загрузку.
        #
        'path' => base_path('app/Storage/Cache/UI/Views'),

        #
        # Указывает время жизни кэша до обновления.
        # 0 - бессрочно до инвалидации.
        #
        'lifetime' => 3600*12,

    ],

    #
    # Включение или отключение отладочного режима.
    # Повышает нагрузку на память и выводит много информации.
    # На бизнес-сервере лучше отключить.
    #
    'debug' => env('APP_DEBUG', true),

    #
    # Устанавливает конкретное расширение поиска шаблонов.
    # Приоритет поиска отдаётся первому расширению в списке.
    #
    'extensions' => ['.blade.php'],

    #
    # Базовая настройка кодировки. Менять нет смысла.
    #
    'encoding' => 'UTF-8',

    #
    # Livewire-подобные компоненты.
    # Сделано на будущее. Сейчас не работает.
    #
    'components' => [
        # 'alert' => AlertComponent::class,
        # 'modal' => ModalComponent::class,
    ],

    #
    # Установка настроек компилятора
    #
    'compiler' => [

        #
        # Базовые разделители для PHP переменных и выражений.
        #
        'brackets' => ['{{', '}}'],

        #
        # Директивы, регистрируемые при инициализации компилятора.
        #
        'directives' => [
            \Neo\Presentation\View\Directives\IfDirective::class,
            \Neo\Presentation\View\Directives\ForeachDirective::class,
            \Neo\Presentation\View\Directives\ForDirective::class,
            \Neo\Presentation\View\Directives\WhileDirective::class,
            \Neo\Presentation\View\Directives\JsonDirective::class,
            \Neo\Presentation\View\Directives\SwitchDirective::class,
            \Neo\Presentation\View\Directives\RawPhpDirective::class,
            \Neo\Presentation\View\Directives\IncludeDirective::class,
            \Neo\Presentation\View\Directives\VerbatimDirective::class,
            \Neo\Presentation\View\Directives\PushDirective::class,
            \Neo\Presentation\View\Directives\StackDirective::class,
            \Neo\Presentation\View\Directives\SlotDirective::class,
            \Neo\Presentation\View\Directives\SessionDirective::class,
            \Neo\Presentation\View\Directives\OnceDirective::class,
            \Neo\Presentation\View\Directives\FragmentDirective::class,
            \Neo\Presentation\View\Directives\CsrfDirective::class,
            \Neo\Presentation\View\Directives\ErrorDirective::class,
            \Neo\Presentation\View\Directives\MethodDirective::class,
            \Neo\Presentation\View\Directives\CacheDirective::class,
            \Neo\Presentation\View\Directives\DebugDirective::class,
        ],

        #
        # Здесь происходит внедрение зависимостей в директивы,
        # Которые в конструкторе требуют реализации контракта.
        # Аргументы конструктора передаются по порядку.
        #
        'dependency_injection' => [
            \Neo\Presentation\View\Directives\CsrfDirective::class => [
                \Neo\Presentation\View\Directives\Contracts\CsrfDirectiveContract::class => \Neo\Presentation\View\Infrastructure\CsrfDirectiveHandler::class,
            ],
            \Neo\Presentation\View\Directives\SessionDirective::class => [
                \Neo\Presentation\View\Directives\Contracts\SessionDirectiveContract::class => \Neo\Presentation\View\Infrastructure\SessionDirectiveHandler::class,
            ],
        ],

    ],
];