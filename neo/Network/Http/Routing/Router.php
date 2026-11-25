<?php

declare(strict_types=1);

namespace Neo\Network\Http\Routing;

use BadMethodCallException;
use Closure;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use Neo\Foundation\Kernel\ServiceContainer;
use Neo\Information\Structures\Str;
use Neo\Network\Http\Context\RequestContext;
use Neo\Network\Http\Contracts\ExceptionHandlerContract;
use Neo\Network\Http\Contracts\RequestContextContract;
use Neo\Network\Http\Contracts\RoutableResource;
use Neo\Network\Http\Contracts\Responsable;
use Neo\Network\Http\JsonResponse;
use Neo\Network\Http\RedirectResponse;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Neo\Network\Http\ViewResponse;
use ReflectionFunction;
use ReflectionMethod;
use Stringable;

/**
 * Маршрутизатор (фасад `Route::`). API совместим с Laravel 12.
 *
 * Состояние маршрутизатора (маршруты, группы, шаблоны, биндеры) создаётся один раз при
 * загрузке приложения и дальше только читается. Всё, что относится к запросу (найденный
 * маршрут и его параметры), хранится в самом Request, поэтому Router безопасно работает
 * при одновременной обработке нескольких запросов в одном процессе (Swoole).
 *
 * @method RouteRegistrar prefix(string $prefix)
 * @method RouteRegistrar middleware(array|string|null $middleware)
 * @method RouteRegistrar withoutMiddleware(array|string $middleware)
 * @method RouteRegistrar name(string $name)
 * @method RouteRegistrar domain(string $domain)
 * @method RouteRegistrar controller(string $controller)
 * @method RouteRegistrar where(array|string $name, ?string $expression = null)
 */
class Router
{
    /** @var list<string> */
    public const array VERBS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    protected RouteCollection $routes;

    /** @var list<array<string, mixed>> стек групп действует только пока грузятся файлы маршрутов */
    protected array $groupStack = [];

    /** @var array<string, string> */
    protected array $patterns = [];

    /** @var array<string, Closure> */
    protected array $binders = [];

    /** @var array<string, Closure> расширения вида `Route::documentation('/docs')`, регистрируются при загрузке приложения */
    protected array $macros = [];

    protected ParameterResolver $resolver;

    public function __construct(
        protected readonly ServiceContainer $container,
        protected readonly MiddlewareRegistry $middleware,
        protected readonly RequestContextContract $context,
    ) {
        $this->routes = new RouteCollection();
        $this->resolver = new ParameterResolver($container, $this);
    }

    // ------------------------------------------------------------------
    // Регистрация
    // ------------------------------------------------------------------

    public function get(string $uri, Closure|array|string|null $action = null): Route
    {
        return $this->addRoute(['GET', 'HEAD'], $uri, $action);
    }

    public function post(string $uri, Closure|array|string|null $action = null): Route
    {
        return $this->addRoute('POST', $uri, $action);
    }

    public function put(string $uri, Closure|array|string|null $action = null): Route
    {
        return $this->addRoute('PUT', $uri, $action);
    }

    public function patch(string $uri, Closure|array|string|null $action = null): Route
    {
        return $this->addRoute('PATCH', $uri, $action);
    }

    public function delete(string $uri, Closure|array|string|null $action = null): Route
    {
        return $this->addRoute('DELETE', $uri, $action);
    }

    public function options(string $uri, Closure|array|string|null $action = null): Route
    {
        return $this->addRoute('OPTIONS', $uri, $action);
    }

    public function any(string $uri, Closure|array|string|null $action = null): Route
    {
        return $this->addRoute(self::VERBS, $uri, $action);
    }

    /** @param list<string>|string $methods */
    public function match(array|string $methods, string $uri, Closure|array|string|null $action = null): Route
    {
        return $this->addRoute(array_map('strtoupper', (array) $methods), $uri, $action);
    }

    public function redirect(string $uri, string $destination, int $status = 302): Route
    {
        return $this->any($uri, static function (Route $route) use ($destination, $status): RedirectResponse {
            $target = (string) preg_replace_callback(
                '/\{(\w+)\??\}/',
                static fn (array $m): string => rawurlencode((string) $route->parameter($m[1], '')),
                $destination,
            );

            return new RedirectResponse($target, $status);
        });
    }

    public function permanentRedirect(string $uri, string $destination): Route
    {
        return $this->redirect($uri, $destination, 301);
    }

    /** @param array<string, mixed> $data */
    public function view(string $uri, string $view, array $data = [], int $status = 200): Route
    {
        return $this->match(['GET', 'HEAD'], $uri, static fn (): ViewResponse => new ViewResponse($view, $data, $status));
    }

    /** Маршрут «всё остальное» (только GET/HEAD), выполняется после обычных. */
    public function fallback(Closure|array|string $action): Route
    {
        $route = $this->addRoute(['GET', 'HEAD'], '{fallbackPlaceholder}', $action);
        $route->isFallback = true;
        $route->where('fallbackPlaceholder', '.*');

        return $route;
    }

    /**
     * @param list<string>|string $methods
     */
    public function addRoute(array|string $methods, string $uri, Closure|array|string|null $action): Route
    {
        return $this->routes->add($this->createRoute($methods, $uri, $action));
    }

    protected function createRoute(array|string $methods, string $uri, Closure|array|string|null $action): Route
    {
        $group = $this->groupStack === [] ? [] : end($this->groupStack);

        if (isset($group['controller']) && is_string($action) && !str_contains($action, '@') && !class_exists($action)) {
            $action = [$group['controller'], $action];
        }

        $route = new Route($methods, $uri, $action);

        if (isset($group['prefix'])) {
            $route->prefix($group['prefix']);
        }

        $patterns = array_filter(
            $this->patterns,
            static fn (string $key): bool => str_contains($route->uri(), '{' . $key),
            ARRAY_FILTER_USE_KEY,
        );

        if ($patterns !== []) {
            $route->where($patterns);
        }

        if (isset($group['where'])) {
            $route->where($group['where']);
        }

        if (isset($group['domain'])) {
            $route->domain($group['domain']);
        }

        if (!empty($group['middleware'])) {
            $route->middleware($group['middleware']);
        }

        if (!empty($group['excluded_middleware'])) {
            $route->withoutMiddleware($group['excluded_middleware']);
        }

        if (isset($group['as'])) {
            $route->setAction([...$route->getAction(), 'as' => $group['as']]);
        }

        return $route;
    }

    // ------------------------------------------------------------------
    // Группы
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $attributes
     * @param Closure|string $routes замыкание или путь к файлу маршрутов
     */
    public function group(array $attributes, Closure|string $routes): static
    {
        $this->groupStack[] = $this->mergeWithLastGroup($attributes);

        try {
            $this->loadRoutes($routes);
        } finally {
            array_pop($this->groupStack);
        }

        return $this;
    }

    /**
     * Загружает файл маршрутов в текущем контексте групп.
     */
    public function loadRoutes(Closure|string $routes): void
    {
        if ($routes instanceof Closure) {
            $routes($this);

            return;
        }

        $router = $this;
        (static function () use ($routes, $router): void {
            require $routes;
        })();
    }

    /** @return array<string, mixed> */
    protected function mergeWithLastGroup(array $new): array
    {
        $old = $this->groupStack === [] ? [] : end($this->groupStack);

        $merged = $new;

        if (isset($old['prefix']) || isset($new['prefix'])) {
            $merged['prefix'] = trim(trim($old['prefix'] ?? '', '/') . '/' . trim($new['prefix'] ?? '', '/'), '/');
        }

        if (isset($old['as']) || isset($new['as'])) {
            $merged['as'] = ($old['as'] ?? '') . ($new['as'] ?? '');
        }

        $merged['middleware'] = [...(array) ($old['middleware'] ?? []), ...(array) ($new['middleware'] ?? [])];
        $merged['excluded_middleware'] = [...(array) ($old['excluded_middleware'] ?? []), ...(array) ($new['excluded_middleware'] ?? [])];
        $merged['where'] = [...($old['where'] ?? []), ...($new['where'] ?? [])];

        foreach (['domain', 'controller'] as $key) {
            if (!isset($new[$key]) && isset($old[$key])) {
                $merged[$key] = $old[$key];
            }
        }

        return $merged;
    }

    public function hasGroupStack(): bool
    {
        return $this->groupStack !== [];
    }

    // ------------------------------------------------------------------
    // Ресурсы
    // ------------------------------------------------------------------

    /**
     * Ресурсные маршруты.
     *
     *   Route::resource('photos', PhotoController::class)->only(['index']);   // контроллер
     *   Route::resource(PostResourceAPI::class);                              // ресурс описывает себя сам
     *   Route::resource(PostResourceAPI::class, ['actions' => ['index']]);    // с настройками группы
     *
     * @param string|RoutableResource $name имя ресурса или самоописываемый ресурс (класс или объект)
     * @param string|array<string, mixed>|null $controller класс контроллера или настройки самоописываемого ресурса
     * @param array<string, mixed> $options
     */
    public function resource(string|RoutableResource $name, string|array|null $controller = null, array $options = []): PendingResourceRegistration|PendingRoutableResource
    {
        # Route::resource('user', UserResourceAPI::class): имя можно задать явно, но не обязательно.
        if (is_string($controller) && is_string($name) && is_subclass_of($controller, RoutableResource::class)) {
            $resource = $this->container->make($controller);

            if (method_exists($resource, 'name')) {
                $resource->name($name);
            }

            return new PendingRoutableResource($this, $resource, $options);
        }

        if (is_string($controller)) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('Для ресурса с контроллером имя ресурса должно быть строкой.');
            }

            return new PendingResourceRegistration(new ResourceRegistrar($this), $name, $controller, $options);
        }

        $resource = $name;

        if (is_string($name)) {
            if (!class_exists($name)) {
                throw new InvalidArgumentException(
                    "Route::resource(\"{$name}\"): класс не найден. Проверьте имя и автозагрузку namespace (autoload.classes в app/Config/Bootstrap.php)."
                );
            }

            $resource = $this->container->make($name);

            if (!$resource instanceof RoutableResource) {
                throw new InvalidArgumentException(
                    "Route::resource(\"{$name}\"): укажите контроллер вторым аргументом или класс, реализующий " . RoutableResource::class . '.'
                );
            }
        }

        return new PendingRoutableResource($this, $resource, $controller ?? $options);
    }

    /** @param array<string, mixed> $options */
    public function apiResource(string $name, string $controller, array $options = []): PendingResourceRegistration
    {
        return $this->resource($name, $controller, array_merge(['only' => ['index', 'show', 'store', 'update', 'destroy']], $options));
    }

    /** @param array<string, string> $resources */
    public function resources(array $resources): void
    {
        foreach ($resources as $name => $controller) {
            $this->resource($name, $controller);
        }
    }

    // ------------------------------------------------------------------
    // Шаблоны и биндинг параметров
    // ------------------------------------------------------------------

    public function pattern(string $key, string $pattern): void
    {
        $this->patterns[$key] = $pattern;
    }

    /** @param array<string, string> $patterns */
    public function patterns(array $patterns): void
    {
        foreach ($patterns as $key => $pattern) {
            $this->pattern($key, $pattern);
        }
    }

    /**
     * Явный биндер параметра: `Route::bind('user', fn (string $value) => User::find($value))`.
     * null из биндера даёт 404.
     */
    public function bind(string $key, Closure $binder): void
    {
        $this->binders[$key] = $binder;
    }

    /** `Route::model('post', Post::class)`: найти по ключу или 404. */
    public function model(string $key, string $class, ?Closure $missing = null): void
    {
        $this->bind($key, static function (string $value) use ($class, $missing) {
            $model = $class::query()->find($value);

            if ($model === null && $missing !== null) {
                return $missing($value);
            }

            return $model;
        });
    }

    public function getBinder(string $key): ?Closure
    {
        return $this->binders[$key] ?? null;
    }

    public function substituteBindings(Route $route): void
    {
        $this->resolver->substitute($route);
    }

    // ------------------------------------------------------------------
    // Обработка запроса
    // ------------------------------------------------------------------

    /**
     * Находит маршрут и выполняет его вместе с маршрутными middleware.
     */
    public function dispatch(Request $request): Response
    {
        [$original, $parameters] = $this->routes->match($request);

        $route = $original->bound($parameters);
        $request->setRoute($route);

        $middleware = $route->computedMiddleware(fn (Route $r): array => $this->gatherRouteMiddleware($r));

        return $this->prepareResponse(
            $request,
            (new Pipeline($this->container, $this->container->make(ExceptionHandlerContract::class)))
                ->send($request)
                ->through($middleware)
                ->then(fn (Request $request): Response => $this->prepareResponse($request, $this->runRoute($route, $request))),
        );
    }

    protected function runRoute(Route $route, Request $request): mixed
    {
        $uses = $route->getAction('uses');

        if ($uses instanceof Closure) {
            $reflection = new ReflectionFunction($uses);

            return $uses(...$this->resolver->resolve($route, $request, $reflection));
        }

        [$class, $method] = $route->parseController();

        if (!class_exists($class)) {
            throw new LogicException("Контроллер [{$class}] не найден.");
        }

        $controller = $this->container->make($class);

        if (!method_exists($controller, $method) && !method_exists($controller, '__call')) {
            throw new LogicException("Метод [{$class}::{$method}()] не существует.");
        }

        $arguments = method_exists($controller, $method)
            ? $this->resolver->resolve($route, $request, new ReflectionMethod($controller, $method))
            : array_values($route->parametersWithoutNulls());

        if (method_exists($controller, 'callAction')) {
            return $controller->callAction($method, $arguments);
        }

        return $controller->{$method}(...$arguments);
    }

    /**
     * Итоговый список middleware маршрута: группы и псевдонимы развёрнуты, добавлены
     * middleware контроллера, убраны исключённые, применён приоритет.
     *
     * @return list<mixed>
     */
    public function gatherRouteMiddleware(Route $route): array
    {
        $middleware = $route->middleware();
        $excluded = $route->excludedMiddleware();

        if ($route->isControllerAction()) {
            [$class, $method] = $route->parseController();

            if (class_exists($class) && is_subclass_of($class, Controllers\HasMiddleware::class)) {
                foreach ($class::middleware() as $item) {
                    if ($item instanceof Controllers\Middleware) {
                        if ($item->appliesTo($method)) {
                            $middleware[] = $item->middleware;
                        }
                    } else {
                        $middleware[] = $item;
                    }
                }
            }
        }

        return $this->middleware->resolve($middleware, $excluded);
    }

    /**
     * Приводит возвращённое действием значение к Response.
     */
    public function prepareResponse(Request $request, mixed $response): Response
    {
        if ($response instanceof Responsable) {
            $response = $response->toResponse($request);
        }

        return match (true) {
            $response instanceof Response => $response,
            $response === null => new Response(''),
            is_array($response), $response instanceof JsonSerializable => new JsonResponse($response),
            is_object($response) && !$response instanceof Stringable && method_exists($response, 'toArray') => new JsonResponse($response->toArray()),
            is_scalar($response), $response instanceof Stringable => new Response((string) $response),
            default => throw new LogicException('Действие маршрута вернуло значение, которое нельзя превратить в ответ: ' . get_debug_type($response) . '.'),
        };
    }

    // ------------------------------------------------------------------
    // Справочная информация
    // ------------------------------------------------------------------

    /** Контейнер приложения: модули, подключающие свои маршруты, берут из него свои сервисы. */
    public function getContainer(): ServiceContainer
    {
        return $this->container;
    }

    public function getRoutes(): RouteCollection
    {
        return $this->routes;
    }

    /** Маршрут текущего запроса. */
    public function current(): ?Route
    {
        if (!$this->context->isEntered()) {
            return null;
        }

        $request = $this->context->request();

        return $request instanceof Request ? $request->route() : null;
    }

    public function currentRouteName(): ?string
    {
        return $this->current()?->getName();
    }

    public function currentRouteAction(): ?string
    {
        return $this->current()?->getActionName();
    }

    public function is(string ...$patterns): bool
    {
        $name = $this->currentRouteName();

        return $name !== null && Str::is($patterns, $name);
    }

    public function has(string $name): bool
    {
        return $this->routes->hasNamedRoute($name);
    }

    public function getByName(string $name): ?Route
    {
        return $this->routes->getByName($name);
    }

    public function getMiddlewareRegistry(): MiddlewareRegistry
    {
        return $this->middleware;
    }

    /** Псевдоним `Route::aliasMiddleware()`. */
    public function aliasMiddleware(string $name, string $class): static
    {
        $this->middleware->alias($name, $class);

        return $this;
    }

    public function middlewareGroup(string $name, array $middleware): static
    {
        $this->middleware->group($name, $middleware);

        return $this;
    }

    // ------------------------------------------------------------------
    // Расширения
    // ------------------------------------------------------------------

    /**
     * Добавляет метод роутеру: `Route::macro('documentation', fn (string $uri) => ...)`, затем
     * `Route::documentation('/docs')`. Замыкание получает $this = Router. Так модули
     * (например, ResourceAPI) расширяют маршрутизацию, не вшиваясь в неё.
     */
    public function macro(string $name, Closure $macro): static
    {
        $this->macros[$name] = $macro->bindTo($this, static::class) ?? $macro;

        return $this;
    }

    public function hasMacro(string $name): bool
    {
        return isset($this->macros[$name]);
    }

    // ------------------------------------------------------------------

    /**
     * `Route::prefix('x')->middleware('auth')->group(...)`: атрибутные методы начинают цепочку.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (isset($this->macros[$method])) {
            return ($this->macros[$method])(...$parameters);
        }

        if (in_array($method, RouteRegistrar::PASSTHRU_ATTRIBUTES, true)) {
            return (new RouteRegistrar($this))->attribute($method, ...$parameters);
        }

        throw new BadMethodCallException('Метод ' . static::class . "::{$method}() не существует.");
    }
}
