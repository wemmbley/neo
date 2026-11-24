<?php

declare(strict_types=1);

return [
    #
    # Пути, к которым применяется CORS (шаблоны со звёздочкой). Пустой список: CORS выключен.
    #
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    #
    # Точные Origin: ['https://app.example.com']. Звёздочка допустима только при supports_credentials = false.
    #
    'allowed_origins' => [],

    # Регулярные выражения: ['#^https://[a-z0-9-]+\.example\.com$#']
    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 600,

    #
    # true = браузер может отправлять куки и Authorization. Тогда нужны только точные Origin.
    #
    'supports_credentials' => false,
];
