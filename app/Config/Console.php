<?php

declare(strict_types=1);

return [

    #
    # Команды приложения (встроенные уже подключены).
    #
    'commands' => [
        App\Commands\HelloCommand::class,
    ],

    #
    # Список папок с тестами которые будут вызываться при php cli test
    #
    'tests' => [
        base_path('app/Tests'),

        #
        # Системные тесты ядра с пакетами.
        # Можно выключить или удалить.
        #
//        base_path('neo/Information/Lang/Tests'),
//        base_path('neo/Information/Transfer/Tests'),
//        base_path('neo/Information/Validator/Tests'),
//        base_path('neo/Information/Persistence/Storage/Tests'),
//        base_path('neo/Network/Cookie/Tests'),
//        base_path('neo/Network/Session/Tests'),
//        base_path('neo/Network/Http/Tests'),
//        base_path('neo/Network/ResourceAPI/Tests'),
//        base_path('neo/Security/Tests'),
//        base_path('neo/Information/Structures/Tests'),
    ],

];
