<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Tasks;

/**
 * Отправка фоновых задач. Один вызов во всех рантаймах, меняется только исполнитель:
 *
 *   sync                  выполняется сразу, в этом же процессе
 *   Swoole без task workers  в отдельной корутине воркера
 *   Swoole с task workers    в процессе task worker (изолированно от запросов)
 *
 * Код, который не должен ждать задачу, вызывает dispatch() и не зависит от среды.
 */
interface TaskDispatcher
{
    /**
     * @param class-string<Task> $task
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $task, array $payload = []): void;
}
