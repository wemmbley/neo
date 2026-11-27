<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Pool;

/**
 * Пул объектов-ресурсов (соединения БД, Redis, HTTP-клиенты). Принадлежит воркеру и не передаётся
 * между процессами. Взятый ресурс обязательно возвращается (`try/finally`).
 */
interface PoolContract
{
    /**
     * Берёт ресурс; если все заняты, ждёт до acquireTimeout.
     *
     * @throws \Neo\Foundation\Runtime\Exceptions\PoolExhaustedException
     */
    public function acquire(): object;

    /** Возвращает ресурс. $broken = true: ресурс испорчен (обрыв связи), его нужно закрыть. */
    public function release(object $resource, bool $broken = false): void;

    /** Закрывает простаивающие ресурсы сверх min. Возвращает число закрытых. */
    public function prune(): int;

    /** Закрывает пул и все его свободные ресурсы. */
    public function close(): void;

    /** Мягкое завершение воркера: освободить простаивающие ресурсы и таймеры, но не мешать идущим запросам. */
    public function drain(): void;

    /** @return array{total: int, idle: int, leased: int, max: int} */
    public function stats(): array;
}
