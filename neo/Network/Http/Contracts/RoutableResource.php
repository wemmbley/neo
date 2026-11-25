<?php

declare(strict_types=1);

namespace Neo\Network\Http\Contracts;

use Neo\Network\Http\Routing\Router;

/**
 * Ресурс, который сам знает свои маршруты: `Route::resource(PostResourceAPI::class)`.
 *
 * Роутер не знает, что внутри (ResourceAPI или что-то своё): он только создаёт объект
 * (имя класса берётся из контейнера) и отдаёт ему себя. Маршруты регистрируются в текущей
 * группе, поэтому префикс, имя и middleware группы действуют как у обычных маршрутов.
 */
interface RoutableResource
{
    /**
     * @param array<string, mixed> $options настройки группы ресурсов (например, `actions`)
     */
    public function registerRoutes(Router $router, array $options = []): static;
}
