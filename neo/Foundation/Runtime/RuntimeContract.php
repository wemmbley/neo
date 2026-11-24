<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime;

/**
 * Рантайм: среда, в которой исполняется приложение (sync SAPI, Swoole, в будущем RoadRunner, FrankenPHP).
 *
 * Контракт минимален: возможности, контекст исполнения, AsyncIO, каналы и defer. Всё остальное
 * (запуск сервера, воркеры) живёт в адаптерах конкретного рантайма, например в
 * `Neo\Network\Http\Server\SwooleServer`.
 */
interface RuntimeContract
{
    public function name(): string;

    public function capabilities(): RuntimeCapabilities;

    /** Контекст текущего исполнения (корутины / Fiber / процесса). */
    public function context(): ExecutionContext;

    public function async(): AsyncIO;

    /** Идентификатор текущей корутины, 0 вне корутин. */
    public function executionId(): int;

    /** Выполняется ли код внутри корутины, то есть возможны переключения на ввод-выводе. */
    public function inCoroutine(): bool;

    /**
     * Выполнить при завершении текущей корутины (LIFO). Нужен для возврата ресурсов в пул.
     * Без корутин выполняется при завершении процесса.
     */
    public function defer(callable $callback): void;

    public function channel(int $capacity = 1): ChannelContract;

    /**
     * Периодический вызов (фоновая чистка пула и т.п.). Возвращает идентификатор таймера.
     * Без цикла событий (sync) таймеры не работают: возвращается 0 и ничего не планируется.
     */
    public function tick(float $seconds, callable $callback): int;

    public function clearTick(int $timerId): void;

    /** Фоновые задачи: исполнитель зависит от рантайма, вызов один и тот же. */
    public function tasks(): Tasks\TaskDispatcher;
}
