<?php

namespace Bootstrap;

use Neo\Autoload\AliasLoader;
use Neo\Autoload\Autoload;
use Neo\Cache\Transfers\CacheTransfer;
use Neo\Kernel\Application;
use Neo\Kernel\DefaultDependencyResolver;
use Neo\Kernel\ProfilerDependencyResolver;
use Neo\Kernel\ServiceContainer;
use Neo\Lang\LangProcessor;
use Neo\Logger\Transfers\LoggerTransfer;
use Neo\Storage\Managers\StorageManager;
use Neo\Storage\Transfers\StorageTransfer;

class Bootstrap
{
    private array $autoloadClasses = [];
    private array $autoloadFiles = [];
    private array $autoloadProviders = [];
    private array $langs = [];
    private array $storage = [];
    private array $reporting = [];
    private array $facades = [];
    private array $cache = [];
    private Application $app;

    public function boot(): void
    {
        # Загружаем автозагрузчик классов и файлов.
        #
        $this->loadAutoloader();

        # Загружаем языки приложения.
        #
        $this->loadLangs();

        # Загружаем ядро приложения.
        #
        $this->loadApplication();

        # Загружаем хранилище.
        #
        $this->loadStorage();

        # Загружаем логгирование.
        #
        $this->loadLogger();

        # Загружаем кэширование.
        #
        $this->loadCache();
    }

    private function loadCache(): void
    {
        $this->app->singleton(
            CacheTransfer::class,
            fn () => new CacheTransfer($this->cache),
        );
    }

    private function loadLogger(): void
    {
        $this->app->singleton(
            LoggerTransfer::class,
            fn () => new LoggerTransfer($this->reporting),
        );
    }

    private function loadStorage()
    {
        # Хранилище необязательно: если конфига нет, ничего не поднимаем.
        #
        if ($this->storage === []) {
            return;
        }

        # Прокидываем базовые настройки из autoload.php
        #
        $this->app->singleton(
            StorageTransfer::class,
            fn () => new StorageTransfer($this->storage),
        );
    }

    private function loadAutoloader(): void
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
    }

    private function loadApplication(): void
    {
        # Создаём основной механизм разрешения зависимостей.
        #
        $defaultResolver = new DefaultDependencyResolver();

        # Оборачиваем его профилировщиком.
        #
        $profiler = new ProfilerDependencyResolver(
            $defaultResolver,
        );

        # Устанавливаем резолвер в зависимости от окружения.
        #
        if(env('APP_PROFILER', true)) {
            $dependencyResolver = $profiler;
        } else {
            $dependencyResolver = $defaultResolver;
        }

        # Создаём контейнер с нужным Resolver.
        #
        $container = new ServiceContainer(
            resolver: $dependencyResolver,
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

        # Добавляем к нему алиасы. Нужны для фасадов статических.
        #
        new AliasLoader()
            ->facades($this->facades)
            ->register();

        # Регистрируем Application для будущих биндов.
        #
        $this->app = $app;
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
        $this->storage = $paths['storage'];
        $this->reporting = $paths['reporting'];
        $this->facades = $paths['facades'];
        $this->cache = $paths['cache'];
    }
}