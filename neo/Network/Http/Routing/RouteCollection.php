<?php

declare(strict_types=1);

namespace Neo\Network\Http\Routing;

use Neo\Network\Http\Exceptions\MethodNotAllowedHttpException;
use Neo\Network\Http\Exceptions\NotFoundHttpException;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;

/**
 * Хранилище маршрутов с индексами. Заполняется при загрузке приложения;
 * индекс строится лениво при первом поиске и дальше только читается.
 */
class RouteCollection
{
    /** @var list<Route> */
    protected array $routes = [];

    /** @var array<string, array<string, Route>> метод => путь => маршрут */
    protected array $static = [];

    /** @var array<string, list<Route>> метод => маршруты с параметрами по порядку регистрации */
    protected array $dynamic = [];

    /** @var array<string, list<Route>> метод => fallback-маршруты */
    protected array $fallbacks = [];

    /** @var array<string, Route> */
    protected array $names = [];

    protected bool $indexed = false;

    public function add(Route $route): Route
    {
        $this->routes[] = $route;
        $this->indexed = false;

        return $route;
    }

    /** @return list<Route> */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function count(): int
    {
        return count($this->routes);
    }

    public function getByName(string $name): ?Route
    {
        $this->index();

        return $this->names[$name] ?? null;
    }

    public function hasNamedRoute(string $name): bool
    {
        return $this->getByName($name) !== null;
    }

    /** Перестроить индексы: нужно, если маршруты правились после первого поиска. */
    public function refresh(): void
    {
        $this->indexed = false;
    }

    protected function index(): void
    {
        if ($this->indexed) {
            return;
        }

        $this->static = $this->dynamic = $this->fallbacks = $this->names = [];

        foreach ($this->routes as $route) {
            if (($name = $route->getName()) !== null) {
                $this->names[$name] ??= $route;
            }

            foreach ($route->methods() as $method) {
                if ($route->isFallback) {
                    $this->fallbacks[$method][] = $route;
                } elseif ($route->isStatic()) {
                    # При дублях побеждает первый зарегистрированный, как в Laravel.
                    $this->static[$method][$route->uri()] ??= $route;
                } else {
                    $this->dynamic[$method][] = $route;
                }
            }
        }

        $this->indexed = true;
    }

    /**
     * @return array{0: Route, 1: array<string, mixed>} исходный маршрут и параметры
     *
     * @throws NotFoundHttpException
     * @throws MethodNotAllowedHttpException
     */
    public function match(Request $request): array
    {
        $this->index();

        $method = $request->method();
        $path = rtrim($request->getPathInfo(), '/') ?: '/';
        $host = $request->getHost();

        if ($found = $this->find($method, $path, $host)) {
            return $found;
        }

        $allowed = [];

        foreach (array_unique([...array_keys($this->static), ...array_keys($this->dynamic), ...array_keys($this->fallbacks)]) as $other) {
            if ($other !== $method && $other !== 'HEAD' && $this->find($other, $path, $host, false) !== null) {
                $allowed[] = $other;
            }
        }

        if ($allowed !== []) {
            if (in_array('GET', $allowed, true)) {
                $allowed[] = 'HEAD';
            }

            if ($method === 'OPTIONS') {
                $allow = implode(', ', array_unique([...$allowed, 'OPTIONS']));

                return [
                    new Route('OPTIONS', $path, static fn () => new Response('', 200, ['Allow' => $allow])),
                    [],
                ];
            }

            throw new MethodNotAllowedHttpException(array_values(array_unique($allowed)));
        }

        throw new NotFoundHttpException();
    }

    /**
     * @return array{0: Route, 1: array<string, mixed>}|null
     */
    private function find(string $method, string $path, string $host, bool $withFallback = true): ?array
    {
        $route = $this->static[$method][$path] ?? null;

        if ($route !== null) {
            return [$route, []];
        }

        foreach ($this->dynamic[$method] ?? [] as $candidate) {
            $parameters = $candidate->matches($path, $host);

            if ($parameters !== null) {
                return [$candidate, $parameters];
            }
        }

        if ($withFallback) {
            foreach ($this->fallbacks[$method] ?? [] as $candidate) {
                $parameters = $candidate->matches($path, $host);

                if ($parameters !== null) {
                    return [$candidate, $parameters];
                }
            }
        }

        return null;
    }
}
