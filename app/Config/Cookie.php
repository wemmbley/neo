<?php

declare(strict_types=1);

return [
    #
    # Default Cookie Options
    #
    'path' => env('COOKIE_PATH', '/'),
    'domain' => env('COOKIE_DOMAIN', env('APP_URL')),
    'secure' => env('COOKIE_SECURE'),
    'http_only' => env('COOKIE_HTTP_ONLY', true),
    'same_site' => env('COOKIE_SAME_SITE', 'lax'),
    'partitioned' => env('COOKIE_PARTITIONED', false),
];
