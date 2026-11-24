<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Sync;

use Neo\Foundation\Runtime\Tasks\TaskDispatcher;
use Neo\Foundation\Runtime\Tasks\TaskRunner;

/** Без фоновых процессов: задача выполняется сразу, до возврата из dispatch(). */
final class SyncTaskDispatcher implements TaskDispatcher
{
    public function dispatch(string $task, array $payload = []): void
    {
        TaskRunner::run($task, $payload);
    }
}
