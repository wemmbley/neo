<?php

declare(strict_types=1);

namespace Neo\Network\Http\Routing;

use Neo\Information\Structures\Str;

/**
 * Регистрирует RESTful-маршруты контроллера:
 *
 *   index   GET        /photos
 *   create  GET        /photos/create
 *   store   POST       /photos
 *   show    GET        /photos/{photo}
 *   edit    GET        /photos/{photo}/edit
 *   update  PUT/PATCH  /photos/{photo}
 *   destroy DELETE     /photos/{photo}
 *
 * Вложенные ресурсы: `photos.comments` -> `/photos/{photo}/comments/{comment}`.
 */
final class ResourceRegistrar
{
    private const array DEFAULTS = ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'];

    public function __construct(private readonly Router $router) {}

    /** @param array<string, mixed> $options */
    public function register(string $name, string $controller, array $options = []): void
    {
        $methods = $this->methods($options);

        if (str_contains($name, '.')) {
            $this->nested($name, $controller, $options);

            return;
        }

        $this->addResource($name, $name, $controller, $options, $methods, $this->parameterFor($name, $options));
    }

    /** @param array<string, mixed> $options */
    private function nested(string $name, string $controller, array $options): void
    {
        $segments = explode('.', $name);
        $last = array_pop($segments);

        $prefix = '';
        foreach ($segments as $segment) {
            $prefix .= $segment . '/{' . $this->parameterFor($segment, $options) . '}/';
        }

        $this->addResource($prefix . $last, $name, $controller, $options, $this->methods($options), $this->parameterFor($last, $options));
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $methods
     */
    private function addResource(string $uri, string $name, string $controller, array $options, array $methods, string $parameter): void
    {
        $base = '/' . trim($uri, '/');
        $member = $base . '/{' . $parameter . '}';

        $map = [
            'index' => [['GET'], $base],
            'create' => [['GET'], $base . '/create'],
            'store' => [['POST'], $base],
            'show' => [['GET'], $member],
            'edit' => [['GET'], $member . '/edit'],
            'update' => [['PUT', 'PATCH'], $member],
            'destroy' => [['DELETE'], $member],
        ];

        foreach ($methods as $method) {
            [$verbs, $path] = $map[$method];

            $route = $this->router->match($verbs, $path, [$controller, $method]);
            $route->name($this->routeName($name, $method, $options));

            if (!empty($options['middleware'])) {
                $route->middleware($options['middleware']);
            }

            if (!empty($options['where'])) {
                $route->where($options['where']);
            }
        }
    }

    /** @param array<string, mixed> $options */
    private function routeName(string $name, string $method, array $options): string
    {
        $names = $options['names'] ?? null;

        if (is_array($names) && isset($names[$method])) {
            return (string) $names[$method];
        }

        return (is_string($names) ? $names : $name) . '.' . $method;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    private function methods(array $options): array
    {
        $methods = self::DEFAULTS;

        if (isset($options['only'])) {
            $methods = array_values(array_intersect($methods, (array) $options['only']));
        }

        if (isset($options['except'])) {
            $methods = array_values(array_diff($methods, (array) $options['except']));
        }

        return $methods;
    }

    /** @param array<string, mixed> $options */
    private function parameterFor(string $resource, array $options): string
    {
        $parameters = $options['parameters'] ?? [];

        if (is_string($parameters)) {
            return $parameters;
        }

        return $parameters[$resource] ?? str_replace('-', '_', Str::singular($resource));
    }
}
