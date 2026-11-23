<?php

declare(strict_types=1);

return [

    # Событие => список слушателей (Closure не подходит: конфиг должен быть сериализуем).
    'listen' => [
        # App\Events\PriceChanged::class => [App\Listeners\NotifyManager::class],
    ],

    # Классы с методом subscribe(Dispatcher $events).
    'subscribe' => [],
];
