<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database;

use Neo\Foundation\Kernel\ServiceProvider;
use Neo\Foundation\Runtime\Lifecycle;
use Neo\Information\Persistence\Database\Console\DbSeedCommand;
use Neo\Information\Persistence\Database\Console\MakeMigrationCommand;
use Neo\Information\Persistence\Database\Console\MakeSeederCommand;
use Neo\Information\Persistence\Database\Console\MigrateCommand;
use Neo\Information\Persistence\Database\Console\MigrateFreshCommand;
use Neo\Information\Persistence\Database\Console\MigrateLogCommand;
use Neo\Information\Persistence\Database\Console\MigratePurgeCommand;
use Neo\Information\Persistence\Database\Console\MigrateRollbackCommand;
use Neo\Information\Persistence\Database\Console\MigrateStatusCommand;
use Neo\Information\Persistence\Database\Migrations\Migrator;
use Neo\Information\Persistence\Database\Schema\Declarative\SchemaMigrator;
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

        # Долгоживущий воркер (Swoole): соединения и пулы принадлежат воркеру, а не master-процессу,
        # и возвращаются в пул в конце каждого запроса. В обычном PHP Lifecycle никто не вызывает.
        $this->app->make(Lifecycle::class)
            ->onWorkerStart(function (): void {
                $manager = $this->app->make(DatabaseManager::class);
                $manager->forgetInherited();
                $manager->warmUp();
            })
            ->resettable(function (): void {
                if ($this->app->getContainer()->hasInstance(DatabaseManager::class)) {
                    $this->app->make(DatabaseManager::class)->releaseCurrent();
                }
            })
            ->onWorkerStop(function (): void {
                if ($this->app->getContainer()->hasInstance(DatabaseManager::class)) {
                    $this->app->make(DatabaseManager::class)->close();
                }
            });

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

        $this->app->singleton(SchemaMigrator::class, fn () => new SchemaMigrator($this->app->make(Migrator::class)));

        $this->commands([
            MigrateCommand::class,
            MigrateRollbackCommand::class,
            MigrateStatusCommand::class,
            MigrateLogCommand::class,
            MigratePurgeCommand::class,
            MigrateFreshCommand::class,
            DbSeedCommand::class,
            MakeMigrationCommand::class,
            MakeSeederCommand::class,
        ]);
    }

    public function boot(): void {}
}
