<?php

declare(strict_types=1);

namespace Neo\Network\Http\Routing\Middleware;

use Closure;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Neo\Network\Http\Routing\Router;

/**
 * Подставляет модели и enum в параметры маршрута ДО остальных middleware
 * (чтобы, например, авторизация видела `$request->route('post')` уже моделью).
 * Модель не найдена: 404.
 */
class SubstituteBindings
{
    public function __construct(protected readonly Router $router) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (($route = $request->route()) !== null) {
            $this->router->substituteBindings($route);
        }

        return $next($request);
    }
}
