<?php

declare(strict_types=1);

namespace Neo\Security\Authorization\Middleware;

use Closure;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Neo\Network\Http\Routing\Router;
use Neo\Security\Authorization\Gate\GateManager;

/**
 * Проверка через Gate прямо в маршруте (middleware `can`):
 *
 *   Route::get('/admin', ...)->middleware('can:admin.panel');
 *   Route::put('/posts/{post}', fn (Post $post) => ...)->middleware('can:update,post');
 *   Route::post('/posts', ...)->middleware('can:create,App\Database\Models\Post');
 *
 * Первый параметр это способность, остальные: имя параметра маршрута (подставляется модель)
 * или имя класса (проверка на классе). Нельзя: 403 (или статус, выбранный политикой).
 * Для гостя 403 тоже, поставьте перед этим `auth`, чтобы гость получил 401 / редирект на вход.
 */
class Authorize
{
    public function __construct(
        protected readonly GateManager $gate,
        protected readonly Router $router,
    ) {}

    public function handle(Request $request, Closure $next, string $ability, string ...$models): Response
    {
        $this->gate->authorize($ability, $this->arguments($request, $models));

        return $next($request);
    }

    /** @param list<string> $models @return list<mixed> */
    protected function arguments(Request $request, array $models): array
    {
        if ($models === []) {
            return [];
        }

        $route = $request->route();

        # Authorize стоит в порядке выполнения раньше SubstituteBindings: подставляем модели сами
        # (повторный вызов безопасен).
        $route !== null && $this->router->substituteBindings($route);

        $arguments = [];

        foreach ($models as $model) {
            $arguments[] = str_contains($model, '\\') ? $model : $request->route($model);
        }

        return $arguments;
    }
}
