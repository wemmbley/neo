<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime;

/** Результат асинхронной задачи. Ждать можно синхронно, в любом режиме AsyncIO. */
interface Future
{
    /**
     * Дожидается результата. Исключение задачи пробрасывается сюда.
     *
     * @throws Exceptions\TimeoutException если задача не уложилась в $timeout (секунды)
     */
    public function await(?float $timeout = null): mixed;

    public function isDone(): bool;
}
