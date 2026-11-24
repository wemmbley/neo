<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime;

use Neo\Foundation\Kernel\ServiceProvider;
use Neo\Foundation\Runtime\Tasks\TaskDispatcher;

/**
 * Настройки рантайма (`app/Config/Runtime.php`) и точки доступа через контейнер:
 * `RuntimeContract`, `AsyncIO`, `ExecutionContext` всегда отдают то, что активно в этот момент
 * (sync или Swoole), поэтому сервис, получивший их через конструктор, работает в любом режиме.
 *
 * `Lifecycle` и `RuntimeContract` привязывает сам Kernel, порядок регистрации провайдеров не важен.
 */
class RuntimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadConfig(base_path('app/Config/Runtime.php'), 'runtime');

        $this->app->bind(AsyncIO::class, static fn (): AsyncIO => Runtime::current()->async());
        $this->app->bind(TaskDispatcher::class, static fn (): TaskDispatcher => Runtime::current()->tasks());
        $this->app->bind(ExecutionContext::class, static fn (): ExecutionContext => Runtime::current()->context());
    }
}
