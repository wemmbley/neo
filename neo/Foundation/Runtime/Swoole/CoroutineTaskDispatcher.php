<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Swoole;

use Neo\Foundation\Runtime\Tasks\TaskDispatcher;
use Neo\Foundation\Runtime\Tasks\TaskRunner;

/** Task workers не включены: задача уходит в отдельную корутину воркера и не задерживает запрос. */
final class CoroutineTaskDispatcher implements TaskDispatcher
{
    public function dispatch(string $task, array $payload = []): void
    {
        if (!SwooleApi::inCoroutine()) {
            TaskRunner::run($task, $payload);

            return;
        }

        SwooleApi::create(static function () use ($task, $payload): void {
            TaskRunner::run($task, $payload);
        });
    }
}
