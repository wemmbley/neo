<?php

declare(strict_types=1);

namespace Neo\Information\Logger\Providers;

use Neo\Foundation\Kernel\ServiceProvider;
use Neo\Information\Logger\Drivers\Basic\ReportDriverContract;
use Neo\Information\Logger\Managers\LogManager;
use Neo\Information\Logger\Transfers\LoggerTransfer;

class LoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadConfig(base_path('app/Config/Logger.php'), 'logger');

        $this->app->singleton(LogManager::class, function () {
            return new LogManager(
                new LoggerTransfer(config('logger'))
            );
        });

        $this->app->singleton(ReportDriverContract::class, LogManager::class);
    }
}