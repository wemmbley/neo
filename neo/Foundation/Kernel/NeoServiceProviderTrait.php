<?php

declare(strict_types=1);

namespace Neo\Foundation\Kernel;

use LogicException;
use Neo\Foundation\Config\Config;
use Neo\Foundation\Console\CommandProcessor;

/**
 * Методы провайдера, специфичные для Neo.
 *
 * Роуты, вьюхи, переводы, миграции и публикации ядро не обрабатывает
 * само: оно запоминает заявки (Kernel::addResource()), а соответствующие
 * модули читают их через Kernel::resources('routes'|'views'|...).
 *
 * @property Kernel $app
 */
trait NeoServiceProviderTrait
{
    #
    # Догружает новый конфиг в функцию config().
    # Аналог Laravel: mergeConfigFrom(). Значения, которые уже
    # заданы приложением (core/config), сильнее значений пакета.
    #
    protected function loadConfig(
        string $path,
        string $key
    ): void {
        if (!is_file($path)) {
            return;
        }

        $defaults = require $path;

        if (!is_array($defaults)) {
            throw new LogicException("Файл настроек {$path} должен возвращать массив.");
        }

        /** @var Config $config */
        $config = $this->app->make(Config::class);

        $config->set($key, array_merge($defaults, (array) $config->get($key, [])));
    }

    #
    # Загружает роуты.
    #
    protected function loadRoutes(
        string $path
    ): void {
        $this->app->addResource('routes', $path);
    }

    protected function loadViews(
        string $path,
        string $namespace
    ): void {
        $this->app->addResource('views', ['path' => $path, 'namespace' => $namespace]);
    }

    protected function loadTranslations(
        string $path,
        ?string $namespace = null
    ): void {
        $this->app->addResource('translations', ['path' => $path, 'namespace' => $namespace]);
    }

    /**
     * @param string|list<string> $paths
     */
    protected function loadMigrations(
        string|array $paths
    ): void {
        foreach ((array) $paths as $path) {
            $this->app->addResource('migrations', $path);
        }
    }

    #
    # Место публикации ресурсов из сервиса:
    # [откуда => куда].
    #
    /**
     * @param array<string, string> $paths
     */
    protected function publish(
        array $paths,
        ?string $group = null
    ): void {
        $this->app->addResource('publishes', ['paths' => $paths, 'group' => $group]);
    }

    #
    # Регистрирует консольные команды (только в CLI).
    #
    /**
     * @param list<class-string> $commands
     */
    protected function commands(
        array $commands
    ): void {
        if (PHP_SAPI !== 'cli') {
            return;
        }

        $this->app->make(CommandProcessor::class)->register($commands);
    }
}
