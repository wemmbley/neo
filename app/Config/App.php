<?php

declare(strict_types=1);

return [
    'name'     => env('APP_NAME', 'Neo'),
    'env'      => env('APP_ENV', 'production'),
    'debug'    => env('APP_DEBUG', false),

    #
    # Операционка. Доступно: unix, win.
    # Влияет на симлинки, пути, и другое.
    #
    'os'       => env('RUNTIME_OS', 'win'),

    #
    # true = контейнер собирает статистику (см. profiler()).
    # Включённый профилировщик значительно понижает производительность.
    #
    'profiler' => env('APP_PROFILER', false),

    #
    # Провайдеры, которые Kernel регистрирует после бутстрапперов.
    #
    'providers' => [
        \Neo\Foundation\Runtime\RuntimeServiceProvider::class,
        \Neo\Security\Crypt\CryptServiceProvider::class,
        \Neo\Foundation\Kernel\Events\EventServiceProvider::class,
        \Neo\Information\Lang\LangServiceProvider::class,
        \Neo\Information\Persistence\Storage\Providers\StorageServiceProvider::class,
        \Neo\Network\Http\HttpServiceProvider::class,
        \Neo\Network\Cookie\CookieServiceProvider::class,
        \Neo\Information\Cache\Providers\CacheServiceProvider::class,
        \Neo\Information\Logger\Providers\LoggerServiceProvider::class,
        \Neo\Information\Validator\ValidatorServiceProvider::class,
        \Neo\Information\Persistence\Database\DatabaseServiceProvider::class,
        \Neo\Network\Session\SessionServiceProvider::class,
        \Neo\Security\SecurityServiceProvider::class,
        \Neo\Information\Persistence\ORM\ORMServiceProvider::class,
        \Neo\Network\ResourceAPI\ResourceAPIServiceProvider::class,
        \Neo\Information\Faker\FakerServiceProvider::class,
        \Neo\Information\Datetime\DatetimeServiceProvider::class,
        \Neo\Presentation\View\ViewServiceProvider::class,
        \App\Providers\AppServiceProvider::class,
    ],

    #
    # Фасады, которым выдаётся короткий алиас (Neo\Foo\Bar => Bar).
    # По умолчанию не обязательно, удобный сахар для вызова фасадов.
    #
    'facades' => [
        \Neo\Network\Http\Facades\Route::class,
        \Neo\Network\Http\Facades\Middleware::class,
        \Neo\Network\Http\Facades\URL::class,
        \Neo\Network\Http\Facades\Redirect::class,
        \Neo\Network\Http\Facades\Response::class,
    ],
];
