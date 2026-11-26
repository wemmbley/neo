<?php

declare(strict_types=1);

namespace Neo\Diagnostic\Benchmark;

use Neo\Diagnostic\Benchmark\Console\BenchmarkRunCommand;
use Neo\Diagnostic\Benchmark\Console\BenchmarkSeedCommand;
use Neo\Diagnostic\Benchmark\Scenarios\Registry;
use Neo\Diagnostic\Benchmark\Support\Runner;
use Neo\Diagnostic\Benchmark\Support\Seeder;
use Neo\Foundation\Config\Config;
use Neo\Foundation\Kernel\ServiceContainer;
use Neo\Foundation\Kernel\ServiceProvider;
use Neo\Information\Persistence\Database\DatabaseManager;

/**
 * Слой нагрузочных тестов. Подключается в app/Config/App.php (providers) после ViewServiceProvider.
 * Включается настройкой benchmark.enabled (BENCHMARK_ENABLED, по умолчанию только local/testing):
 * тогда появляются маршруты /benchmark/*, миграция таблиц bench_* и шаблоны benchmark::*.
 */
final class BenchmarkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadConfig(base_path('app/Config/Benchmark.php'), 'benchmark');

        $this->app->singleton(Registry::class, static fn (): Registry => new Registry());
        $this->app->singleton(Runner::class, static fn (): Runner => new Runner());
        $this->app->singleton(Seeder::class, static fn (ServiceContainer $c): Seeder => new Seeder($c->make(DatabaseManager::class)));

        $this->commands([BenchmarkRunCommand::class, BenchmarkSeedCommand::class]);

        /** @var Config $config */
        $config = $this->app->make(Config::class);

        if (!filter_var($config->get('benchmark.enabled', false), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $config->set('view.namespaces.benchmark', __DIR__ . '/Views');

        $this->loadMigrations(__DIR__ . '/Migrations');
        $this->loadRoutes(__DIR__ . '/Routes/benchmark.php');
    }
}
