<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime;

/**
 * Единый способ вызова, но не единая семантика (Runtime Specification, п. 6).
 * Код, использующий AsyncIO, обязан быть корректен и при режиме None, где всё выполняется последовательно.
 */
interface AsyncIO
{
    public function mode(): AsyncIoMode;

    /** Запускает задачу. В режиме None выполняет её сразу и возвращает готовый Future. */
    public function run(callable $task): Future;

    /**
     * Выполняет задачи и возвращает результаты с теми же ключами. Если одна падает, остальные отменяются
     * (где это возможно), исключение пробрасывается.
     *
     * @param array<array-key, callable> $tasks
     * @return array<array-key, mixed>
     */
    public function all(array $tasks): array;

    public function sleep(float $seconds): void;

    /**
     * Выполняет задачу с ограничением по времени.
     * Режим None прервать задачу не может: она просто выполняется до конца.
     *
     * @throws Exceptions\TimeoutException
     */
    public function timeout(float $seconds, callable $task): mixed;
}
