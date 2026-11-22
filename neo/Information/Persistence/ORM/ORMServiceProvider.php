<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM;

use Neo\Foundation\Kernel\ServiceProvider;
use Neo\Foundation\Kernel\Events\Dispatcher;
use Neo\Information\Persistence\Database\DatabaseManager;
use Neo\Information\Persistence\ORM\Factories\FactoryManager;

/**
 * Подключает Model к соединениям и фабрикам. Требует DatabaseServiceProvider и EventServiceProvider.
 */
class ORMServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            FactoryManager::class,
            fn () => new FactoryManager((string) config('database.factories_namespace', 'Database\\Factories\\')),
        );
    }

    public function boot(): void
    {
        Model::setConnectionResolver($this->app->make(DatabaseManager::class));
        Model::setFactoryManager($this->app->make(FactoryManager::class));
        Model::setEventDispatcher($this->app->make(Dispatcher::class));
    }
}
