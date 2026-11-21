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
    ],

];
