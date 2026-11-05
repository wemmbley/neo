<?php

declare(strict_types=1);

namespace Neo\Logger\Providers;

use Neo\Kernel\ServiceProvider;
use Neo\Logger\Drivers\Basic\ReportDriverContract;
use Neo\Logger\Managers\LogManager;

class LoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Сам LogManager (синглтон с LoggerTransfer) регистрируется
        // в Bootstrap приложения.

        // Контракт драйвера отдаёт менеджер: он реализует его
        // и делегирует драйверу по умолчанию.

        $this->app->bind(
            ReportDriverContract::class,
            fn () => $this->app->make(LogManager::class)
        );
    }

    public function boot(): void {}
}