<?php

declare(strict_types=1);

namespace Neo\Network\Http\Routing;

use Neo\Network\Http\Contracts\RoutableResource;

/**
 * Отложенная регистрация самоописывающегося ресурса (ResourceAPI): регистрируется, когда
 * выражение закончилось, поэтому настройки можно писать цепочкой:
 *
 *   Route::resource(PostResourceAPI::class)->name('post')->actions(['index', 'show']);
 *
 * Вызовы, которых нет у этого класса, передаются самому ресурсу.
 */
final class PendingRoutableResource
{
    private bool $registered = false;

    /** @var array<string, mixed> */
    private array $groupAttributes = [];

    /** @param array<string, mixed> $options */
    public function __construct(
        private readonly Router $router,
        private readonly RoutableResource $resource,
        private readonly array $options = [],
    ) {}

    /** Атрибуты группы (префикс, middleware) для регистрации через RouteRegistrar. @param array<string, mixed> $attributes */
    public function inGroup(array $attributes): static
    {
        $this->groupAttributes = $attributes;

        return $this;
    }

    public function resource(): RoutableResource
    {
        return $this->resource;
    }

    /** @param array<int, mixed> $parameters */
    public function __call(string $method, array $parameters): static
    {
        $this->resource->{$method}(...$parameters);

        return $this;
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        if ($this->groupAttributes === []) {
            $this->resource->registerRoutes($this->router, $this->options);

            return;
        }

        $this->router->group($this->groupAttributes, fn () => $this->resource->registerRoutes($this->router, $this->options));
    }

    public function __destruct()
    {
        $this->register();
    }
}
