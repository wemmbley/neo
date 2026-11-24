<?php

declare(strict_types=1);

namespace Neo\Network\Http\Routing;

/**
 * Отложенная регистрация ресурсных маршрутов: `Route::resource('photos', PhotoController::class)->only(['index'])`.
 * Маршруты регистрируются при уничтожении объекта (конец оператора), как в Laravel,
 * либо явным вызовом {@see register()}.
 */
final class PendingResourceRegistration
{
    private bool $registered = false;

    /** @param array<string, mixed> $options */
    public function __construct(
        private readonly ResourceRegistrar $registrar,
        private readonly string $name,
        private readonly string $controller,
        private array $options,
    ) {}

    /** @param list<string>|string $methods */
    public function only(array|string $methods): static
    {
        $this->options['only'] = (array) $methods;

        return $this;
    }

    /** @param list<string>|string $methods */
    public function except(array|string $methods): static
    {
        $this->options['except'] = (array) $methods;

        return $this;
    }

    /** @param array<string, string>|string $names */
    public function names(array|string $names): static
    {
        $this->options['names'] = $names;

        return $this;
    }

    public function name(string $method, string $name): static
    {
        $this->options['names'][$method] = $name;

        return $this;
    }

    /** @param array<string, string>|string $parameters */
    public function parameters(array|string $parameters): static
    {
        $this->options['parameters'] = $parameters;

        return $this;
    }

    public function parameter(string $resource, string $parameter): static
    {
        $this->options['parameters'][$resource] = $parameter;

        return $this;
    }

    /** @param array<mixed>|string $middleware */
    public function middleware(array|string $middleware): static
    {
        $this->options['middleware'] = [...(array) ($this->options['middleware'] ?? []), ...(array) $middleware];

        return $this;
    }

    /** @param array<string, string>|string $name */
    public function where(array|string $name, ?string $expression = null): static
    {
        $this->options['where'] = [...($this->options['where'] ?? []), ...(is_array($name) ? $name : [$name => $expression])];

        return $this;
    }

    public function shallow(bool $shallow = true): static
    {
        $this->options['shallow'] = $shallow;

        return $this;
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;
        $this->registrar->register($this->name, $this->controller, $this->options);
    }

    public function __destruct()
    {
        $this->register();
    }
}
