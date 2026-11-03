<?php

namespace Bootstrap;

use Neo\Autoload\Autoload;
use Neo\Kernel\Application;
use Neo\Kernel\DefaultDependencyResolver;
use Neo\Kernel\ProfilerDependencyResolver;
use Neo\Kernel\ServiceContainer;
use Neo\Lang\LangProcessor;

class Bootstrap
{
    private array $autoloadClasses = [];
    private array $autoloadFiles = [];
    private array $autoloadProviders = [];
    private array $langs = [];

    public function boot(): void
    {
        # Подгружаем первичный драйвер автозагрузки.
        #
        require_once dirname(__DIR__) . '/neo/Autoload/Autoload.php';

        # Устанавливаем все пути загрузки в класс.
        #
        $this->setAutoloadPaths();

        # Реализуем автозагрузку одним методом.
        #
        $autoload = new Autoload(
            classes: $this->autoloadClasses,
            files: $this->autoloadFiles
        );
        $autoload->load();

        # Загружаем языки приложения.
        #
        $this->loadLangs();

        # Загружаем ядро приложения.
        #

        # Создаём основной механизм разрешения зависимостей.
        #
        $defaultResolver = new DefaultDependencyResolver();

        # Оборачиваем его профилировщиком.
        $profiler = new ProfilerDependencyResolver(
            $defaultResolver,
        );

        # Создаём контейнер с нужным Resolver.
        #
        $container = new ServiceContainer(
            resolver: $profiler,
        );

        # Собираем приложение.
        #
        $app = new Application(
            container: $container,
            providers: $this->autoloadProviders,
        );

        # Запускаем приложение.
        #
        $app->run();
    }

    /**
     * Метод получения всех доступных пространств имён для подгрузки.
     *
     * @return void
     * @throws \Exception
     */
    private function setAutoloadPaths(): void
    {
        # Подгружаем пути автозагрузки из корневого файла.
        #
        $paths = dirname(__DIR__) . '/autoload.php';
        $paths = require $paths;

        # Если нечего подгружать, бросаем исключение.
        # Мы обязаны найти ядро Neo.
        #
        if(!isset($paths['namespaces'])) {
            throw new \Exception(
                'В файле autoload.php не обнаружен namespaces.'
            );
        }

        # Устанавливаем все пути из файла autoload.php
        #
        $this->autoloadClasses = $paths['namespaces'];
        $this->autoloadFiles = $paths['files'];
        $this->autoloadProviders = $paths['providers'];
        $this->langs = $paths['langs'];
    }

    private function loadLangs(): void
    {
        $lang = new LangProcessor(
            locale: env('APP_LOCALE', 'ru'),
            fallbackLocale: env('APP_LOCALE', 'en'),
        );

        foreach($this->langs as $namespace => $directory) {
            $lang->addNamespace(
                $namespace,
                $directory
            );
        }

        $lang->run();
    }
}