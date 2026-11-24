<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Tasks;

use InvalidArgumentException;
use Neo\Foundation\Kernel\Kernel;
use Throwable;

/** Выполняет задачу: создаёт её через контейнер приложения. Ошибки логируются, а не пробрасываются. */
final class TaskRunner
{
    /** @param class-string<Task> $task */
    public static function run(string $task, array $payload = []): bool
    {
        try {
            $handler = Kernel::getInstance()->make($task);

            if (!$handler instanceof Task) {
                throw new InvalidArgumentException("{$task} не реализует " . Task::class . '.');
            }

            $handler->handle($payload);

            return true;
        } catch (Throwable $e) {
            error_log("[runtime] task {$task} failed: " . $e::class . ': ' . $e->getMessage());

            return false;
        }
    }
}
