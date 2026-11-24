<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Swoole;

use Neo\Foundation\Runtime\Tasks\TaskDispatcher;
use RuntimeException;

/** Task workers Swoole: задача сериализуется и выполняется в отдельном процессе. */
final class SwooleTaskDispatcher implements TaskDispatcher
{
    public const string KEY_TASK = 'task';

    public const string KEY_PAYLOAD = 'payload';

    public function __construct(private readonly object $server) {}

    public function dispatch(string $task, array $payload = []): void
    {
        $id = $this->server->task([self::KEY_TASK => $task, self::KEY_PAYLOAD => $payload]);

        if ($id === false) {
            throw new RuntimeException("Не удалось отправить задачу {$task}: очередь task workers переполнена или они не запущены.");
        }
    }
}
