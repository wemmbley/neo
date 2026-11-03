<?php

declare(strict_types=1);

namespace Neo\Kernel;

use LogicException;

/**
 * Это точка сборки всего семантического пространства Kernel:
 * Разворот контейнера, внедрение зависимостей, провайдеры.
 */
final class Application
{
    private static ?self $instance = null;

    public function __construct(
        private readonly ServiceContainer $container,
        private array $providers = [],
    ) {
        self::$instance = $this;
    }

    /**
     * Запускает жизненный цикл приложения.
     */
    public function boot(): void
    {
        // Сначала регистрируем все провайдеры.
        foreach ($this->providers as $provider) {
            $provider->register();
        }

        // Затем запускаем все провайдеры.
        foreach ($this->providers as $provider) {
            $provider->boot();
        }
    }

    /**
     * Запускает приложение.
     */
    public function run(): void
    {
        $this->boot();
    }

    /**
     * Возвращает текущий экземпляр приложения.
     */
    public static function getInstance(): self
    {
        return self::$instance
            ?? throw new LogicException(
                'Приложение не создано.'
            );
    }

    /**
     * Разрешает зависимость через контейнер.
     */
    public function make(
        string $abstract,
        array $parameters = [],
    ): mixed {
        return $this->container->make(
            $abstract,
            $parameters,
        );
    }

    /**
     * Возвращает контейнер приложения.
     */
    public function getContainer(): ServiceContainer
    {
        return $this->container;
    }

    /**
     * Регистрирует провайдера.
     */
    public function registerProvider(object $provider): void
    {
        $this->providers[] = $provider;
    }

    /**
     * Возвращает зарегистрированных провайдеров.
     */
    public function getProviders(): array
    {
        return $this->providers;
    }
}