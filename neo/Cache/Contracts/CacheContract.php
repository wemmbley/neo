<?php

declare(strict_types=1);

namespace Neo\Cache\Contracts;

use Closure;
use DateInterval;
use DateTimeInterface;

interface CacheContract
{
    /**
     * Получить значение из кэша.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Получить несколько значений.
     */
    public function many(array $keys): array;

    /**
     * Проверить существование ключа.
     */
    public function has(string|array $key): bool;

    /**
     * Сохранить значение в кэш.
     */
    public function put(
        string $key,
        mixed $value,
        DateTimeInterface|DateInterval|int|null $ttl = null
    ): bool;

    /**
     * Сохранить несколько значений.
     */
    public function putMany(
        array $values,
        DateTimeInterface|DateInterval|int|null $ttl = null
    ): bool;

    /**
     * Удалить значение.
     */
    public function forget(string|array $key): bool;

    /**
     * Получить значение или вычислить и сохранить его.
     */
    public function remember(
        string $key,
        DateTimeInterface|DateInterval|int|null $ttl,
        Closure $callback
    ): mixed;

    /**
     * Получить значение или вычислить и сохранить навсегда.
     */
    public function rememberForever(
        string $key,
        Closure $callback
    ): mixed;

    /**
     * Сохранить значение навсегда.
     */
    public function forever(string $key, mixed $value): bool;

    /**
     * Удалить все значения из текущего хранилища.
     */
    public function flush(): bool;

    /**
     * Сохранить значение, только если ключа ещё нет.
     */
    public function add(
        string $key,
        mixed $value,
        DateTimeInterface|DateInterval|int|null $ttl = null
    ): bool;

    /**
     * Получить и сразу удалить:
     */
    public function pull(string $key, mixed $default = null): mixed;
}