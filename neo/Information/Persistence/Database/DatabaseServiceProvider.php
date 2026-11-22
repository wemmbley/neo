<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database;

use Neo\Foundation\Kernel\ServiceProvider;
use Neo\Information\Persistence\Database\Console\DbSeedCommand;
use Neo\Information\Persistence\Database\Console\MakeMigrationCommand;
use Neo\Information\Persistence\Database\Console\MakeSeederCommand;
use Neo\Information\Persistence\Database\Console\MigrateCommand;
use Neo\Information\Persistence\Database\Console\MigrateFreshCommand;
use Neo\Information\Persistence\Database\Console\MigrateRollbackCommand;
use Neo\Information\Persistence\Database\Console\MigrateStatusCommand;
use Neo\Information\Persistence\Database\Migrations\Migrator;
use Neo\Information\Persistence\Database\Schema\SchemaBuilder;

/**
 * Регистрация слоя БД. Добавить в `app.providers`; фасады DB и Schema: в `app.facades`.
 */
class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadConfig(__DIR__ . '/config.php', 'database');

        $this->app->singleton(
            DatabaseManager::class,
            fn () => new DatabaseManager((array) config('database')),
        );

        # Schema:: всегда работает с соединением по умолчанию на момент вызова.
        $this->app->bind(
            SchemaBuilder::class,
            fn () => $this->app->make(DatabaseManager::class)->schema(),
        );

        $this->app->singleton(Migrator::class, function () {
            $cfg = (array) config('database.migrations', []);

            return new Migrator(
                $this->app->make(DatabaseManager::class),
                $cfg,
                (array) ($cfg['paths'] ?? [base_path('app/Database/Migrations')]),
            );
        });

        $this->commands([
            MigrateCommand::class,
            MigrateRollbackCommand::class,
            MigrateStatusCommand::class,
            MigrateFreshCommand::class,
            DbSeedCommand::class,
            MakeMigrationCommand::class,
            MakeSeederCommand::class,
        ]);
    }

    public function boot(): void {}
}
