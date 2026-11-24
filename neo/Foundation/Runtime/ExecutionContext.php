<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime;

/**
 * Хранилище данных текущего исполнения: запроса, а при наличии корутин и корутины.
 *
 * Где лежит физически, решает рантайм: контекст корутины (Swoole), контекст Fiber, свойство процесса.
 * Глобальные и статические переменные для данных запроса запрещены: используйте {@see Context}.
 */
interface ExecutionContext
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function has(string $key): bool;

    public function forget(string $key): void;

    /** Контекст родителя (корутина, создавшая текущую) или null. Наследование явное, не копирующее. */
    public function parent(): ?ExecutionContext;
}
