<?php

namespace Neo\Kernel;

use LogicException;

/**
 * Это - точка сборки всего семантического пространства Kernel:
 * Разворот контейнера, внедрение зависимостей, провайдеры.
 */
final class Application
{
    private static ?self $instance = null;

    public function __construct(
        private readonly ServiceContainer $container,
        private array                     $providers = [],
    ) {
        self::$instance = $this;
    }

    public function boot(): void
    {
        foreach ($this->providers as $provider) {

            # Выполняем метод register всех доступных Провайдеров.
            #
            $provider->register($this);

            # Выполняем метод boot всех доступных Провайдеров.
            #
            $provider->boot($this);

        }
    }

    public function run(): void
    {
        $this->boot();
    }

    public static function getInstance(): self
    {
        return self::$instance
            ?? throw new LogicException(
                'Приложение не создано.'
            );
    }

    public function make(string $abstract, array $parameters)
    {
        return $this->container->make($abstract, $parameters);
    }

    public function getContainer(): ServiceContainer
    {
        return $this->container;
    }

    public function registerProvider(object $provider): void
    {
        $this->providers[] = $provider;
    }

    public function getProviders(): array
    {
        return $this->providers;
    }
}