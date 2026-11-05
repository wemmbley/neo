<?php

declare(strict_types=1);

namespace Neo\Kernel;

use Closure;

class ServiceContainer
{
    private array $bindings = [];

    private array $instances = [];

    private array $resolving = [];

    /**
     * Ленивые загрузчики: abstract => closure, которая зарегистрирует
     * провайдера, владеющего этим сервисом.
     *
     * @var array<string, Closure>
     */
    private array $deferred = [];

    private DependencyResolverContract $resolver;

    public function __construct(
        ?DependencyResolverContract $resolver = null,
    )
    {
        $this->resolver = $resolver ?? new DefaultDependencyResolver();
    }

    public function setResolver(
        DependencyResolverContract $resolver,
    ): static
    {
        $this->resolver = $resolver;

        return $this;
    }

    public function getResolver(): DependencyResolverContract
    {
        return $this->resolver;
    }

    public function bind(
        string              $abstract,
        Closure|string|null $concrete = null,
        bool                $shared = false,
    ): static
    {
        # Явная привязка важнее ленивой.
        #
        unset($this->instances[$abstract], $this->deferred[$abstract]);

        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'shared' => $shared,
        ];

        return $this;
    }

    public function singleton(
        string              $abstract,
        Closure|string|null $concrete = null,
    ): static
    {
        return $this->bind($abstract, $concrete, true);
    }

    public function instance(string $abstract, mixed $instance): mixed
    {
        unset($this->bindings[$abstract], $this->deferred[$abstract]);

        return $this->instances[$abstract] = $instance;
    }

    public function forget(string $abstract): void
    {
        unset(
            $this->bindings[$abstract],
            $this->instances[$abstract],
            $this->deferred[$abstract],
        );
    }

    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract])
            || isset($this->deferred[$abstract])
            || array_key_exists($abstract, $this->instances);
    }

    # -----------------------------------------------------------
    # Ленивая загрузка
    # -----------------------------------------------------------
    #

    /**
     * Сервис появится, когда его впервые запросят: до этого момента
     * контейнер вызовет $loader, и тот обязан добавить привязку.
     */
    public function defer(string $abstract, Closure $loader): static
    {
        $this->deferred[$abstract] = $loader;

        return $this;
    }

    /**
     * @param list<string> $abstracts
     */
    public function undefer(array $abstracts): void
    {
        foreach ($abstracts as $abstract) {
            unset($this->deferred[$abstract]);
        }
    }

    public function isDeferred(string $abstract): bool
    {
        return isset($this->deferred[$abstract]);
    }

    public function make(
        string $abstract,
        array  $parameters = [],
    ): mixed
    {
        # Загрузчик сам снимает с себя ленивость, повторно не сработает.
        #
        if (isset($this->deferred[$abstract])) {
            ($this->deferred[$abstract])();
        }

        if ($this->hasInstance($abstract)) {
            return $this->getInstance($abstract);
        }

        if ($this->isResolving($abstract)) {
            throw new ServiceContainerException(
                'Циклическая зависимость: '
                . implode(' -> ', $this->getResolving())
                . ' -> ' . $abstract . '.'
            );
        }

        return $this->resolver->resolve(
            $this,
            $abstract,
            $parameters,
        );
    }

    public function get(string $id): mixed
    {
        return $this->make($id);
    }

    public function call(
        callable|array|string $callback,
        array                 $parameters = [],
    ): mixed
    {
        return $this->resolver->call(
            $this,
            $callback,
            $parameters,
        );
    }

    public function getBinding(string $abstract): ?array
    {
        return $this->bindings[$abstract] ?? null;
    }

    public function hasInstance(string $abstract): bool
    {
        return array_key_exists($abstract, $this->instances);
    }

    public function getInstance(string $abstract): mixed
    {
        return $this->instances[$abstract];
    }

    public function storeInstance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    public function isResolving(string $abstract): bool
    {
        return isset($this->resolving[$abstract]);
    }

    public function startResolving(string $abstract): void
    {
        $this->resolving[$abstract] = true;
    }

    public function stopResolving(string $abstract): void
    {
        unset($this->resolving[$abstract]);
    }

    public function getResolving(): array
    {
        return array_keys($this->resolving);
    }

    public function getProfiler(): ?ProfilerDependencyResolver
    {
        return $this->resolver instanceof ProfilerDependencyResolver
            ? $this->resolver
            : null;
    }
}