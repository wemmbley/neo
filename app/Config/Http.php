<?php

declare(strict_types=1);

return [

    #
    # Файлы маршрутов. У каждого свои префикс, имя и группа middleware (как web.php / api.php в Laravel).
    #
    'routes' => [
        ['path' => base_path('src/UI/Routes/web.php'), 'middleware' => ['web']],
        ['path' => base_path('src/API/Routes/api.php'), 'middleware' => ['api'], 'prefix' => 'api', 'name' => 'api.'],
    ],

    #
    # Принудительный корень URL (например, https://site.com) для route()/url().
    # Если не задан, берётся из запроса. На боевом сайте задайте его вместе с security.trusted_hosts.
    #
    'url' => [
        'root' => env('HTTP_URL_ROOT'),
        'force_https' => env('HTTP_FORCE_HTTPS', false),
    ],

    #
    # Максимальный размер тела запроса в байтах (null = post_max_size из php.ini).
    #
    'max_body_size' => env('HTTP_MAX_BODY_SIZE'),

    #
    # Запросов в минуту для лимитера «api» по умолчанию.
    #
    'api_rate_limit' => (int) env('HTTP_API_RATE_LIMIT', 60),

    #
    # Свои middleware. Ключи необязательны: по умолчанию используются встроенные.
    #
    # 'middleware' => [
    #     'aliases'  => ['auth' => \App\Middleware\Authenticate::class],
    #     'groups'   => ['web' => [...], 'api' => [...], 'admin' => [...]],
    #     'global'   => [...],
    #     'priority' => [...],
    # ],

];
