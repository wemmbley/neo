<?php

declare(strict_types=1);

return [
    #
    # Доверенные прокси (балансировщик, CDN): IP, подсети CIDR или '*'.
    # Пусто = заголовкам X-Forwarded-* НЕ верим, IP клиента = адрес соединения.
    # '*' включайте, только если сервер недоступен напрямую.
    #
    'proxies' => env('TRUSTED_PROXIES', []),

    #
    # Разрешённые значения Host: 'example.com', '*.example.com', '#^(www\.)?example\.com$#'.
    # Пусто = не проверять. НА БОЕВОМ САЙТЕ ЗАПОЛНИТЕ: защита от подмены Host.
    #
    'trusted_hosts' => [],

    'csrf' => [
        # Пути без CSRF-проверки (вебхуки): ['webhooks/*']
        'except' => [],
        # Отклонять запросы с заголовком Origin другого хоста
        'verify_origin' => true,
        'allowed_origins' => [],
        # Выдавать куку XSRF-TOKEN для JS-клиентов (axios, fetch)
        'xsrf_cookie' => true,
    ],

    'headers' => [
        'enabled' => true,
        'x_content_type_options' => 'nosniff',
        'x_frame_options' => 'SAMEORIGIN',
        'referrer_policy' => 'strict-origin-when-cross-origin',
        'cross_origin_opener_policy' => 'same-origin',
        'permissions_policy' => null, // 'camera=(), microphone=(), geolocation=()'
        # Только для HTTPS-запросов. false = не отправлять.
        'hsts' => ['max_age' => 31536000, 'include_subdomains' => true, 'preload' => false],
        # Content-Security-Policy. {nonce} заменяется на значение csp_nonce() текущего запроса.
        # Пример: "default-src 'self'; script-src 'self' 'nonce-{nonce}'; object-src 'none'"
        'csp' => null,
        'csp_report_only' => false,
    ],
];
