<?php

declare(strict_types=1);

namespace Neo\Cache\Stores;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Neo\Cache\Contracts\CacheStoreContract;
use Neo\Cache\Exceptions\CacheException;
use Throwable;

/**
 * Общая логика хранилищ: TTL, ключи, remember/add/pull, increment.
 * Конкретный стор реализует только fetch / write / remove / flush.
 *
 * Запись внутри стора: ['value' => mixed, 'expires' => ?int (unix time, null = навсегда)].
 * Значение null кэшируется как обычное значение: has() для него true.
 */
abstract class BasicStore implements CacheStoreContract
{
    public function __construct(protected string $prefix = '') {}

    /**
     * Прочитать запись как есть (без проверки срока жизни). Ключ без префикса.
     *
     * @return array{value: mixed, expires: ?int}|null
     */
    abstract protected function fetch(string $key): ?array;

    abstract protected function write(string $key, mixed $value, ?int $expiresAt): bool;

    abstract protected function remove(string $key): bool;

    abstract public function flush(): bool;

    /**
     * Записать, только если записи нет. Сторы могут переопределить атомарно.
     */
    protected function writeIfAbsent(string $key, mixed $value, ?int $expiresAt): bool
    {
        if ($this->entry($key) !== null) {
            return false;
        }

        return $this->write($key, $value, $expiresAt);
    }

    protected function now(): int
    {
        return time();
    }

    protected function prefixed(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * Живая запись или null. Просроченную запись удаляет.
     *
     * @return array{value: mixed, expires: ?int}|null
     */
    protected function entry(string $key): ?array
    {
        $this->assertKey($key);

        $entry = $this->fetch($key);

        if ($entry === null) {
            return null;
        }

        if ($entry['expires'] !== null && $entry['expires'] <= $this->now()) {
            $this->remove($key);

            return null;
        }

        return $entry;
    }

    protected function assertKey(string $key): void
    {
        if ($key === '') {
            throw new CacheException('Cache key must not be empty.');
        }
    }

    /**
     * TTL → абсолютный unix time. null = навсегда.
     */
    protected function expiration(DateTimeInterface|DateInterval|int|null $ttl): ?int
    {
        return match (true) {
            $ttl === null => null,
            is_int($ttl) => $this->now() + $ttl,
            $ttl instanceof DateInterval => (new DateTimeImmutable('@' . $this->now()))->add($ttl)->getTimestamp(),
            default => $ttl->getTimestamp(),
        };
    }

    /**
     * @throws CacheException
     */
    protected function encode(string $key, mixed $payload): string
    {
        try {
            return serialize($payload);
        } catch (Throwable $e) {
            throw new CacheException("Value for cache key [{$key}] cannot be serialized: {$e->getMessage()}", 0, $e);
        }
    }

    // ── Чтение ─────────────────────────────────────────────

    public function get(string $key, mixed $default = null): mixed
    {
        $entry = $this->entry($key);

        if ($entry !== null) {
            return $entry['value'];
        }

        return $default instanceof Closure ? $default() : $default;
    }

    public function many(array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }

        return $result;
    }

    public function has(string|array $key): bool
    {
        foreach ((array) $key as $k) {
            if ($this->entry($k) === null) {
                return false;
            }
        }

        return true;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $entry = $this->entry($key);

        if ($entry === null) {
            return $default instanceof Closure ? $default() : $default;
        }

        $this->remove($key);

        return $entry['value'];
    }

    // ── Запись ─────────────────────────────────────────────

    /**
     * ttl <= 0 (или дата в прошлом) удаляет ключ.
     */
    public function put(string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null): bool
    {
        $this->assertKey($key);

        $expiresAt = $this->expiration($ttl);

        if ($expiresAt !== null && $expiresAt <= $this->now()) {
            return $this->remove($key);
        }

        return $this->write($key, $value, $expiresAt);
    }

    public function putMany(array $values, DateTimeInterface|DateInterval|int|null $ttl = null): bool
    {
        $ok = true;

        foreach ($values as $key => $value) {
            $ok = $this->put((string) $key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->put($key, $value, null);
    }

    public function add(string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null): bool
    {
        $this->assertKey($key);

        $expiresAt = $this->expiration($ttl);

        if ($expiresAt !== null && $expiresAt <= $this->now()) {
            return false;
        }

        return $this->writeIfAbsent($key, $value, $expiresAt);
    }

    public function forget(string|array $key): bool
    {
        $ok = true;

        foreach ((array) $key as $k) {
            $this->assertKey($k);
            $ok = $this->remove($k) && $ok;
        }

        return $ok;
    }

    // ── remember ───────────────────────────────────────────

    public function remember(string $key, DateTimeInterface|DateInterval|int|null $ttl, Closure $callback): mixed
    {
        $entry = $this->entry($key);

        if ($entry !== null) {
            return $entry['value'];
        }

        $value = $callback();

        $this->put($key, $value, $ttl);

        return $value;
    }

    public function rememberForever(string $key, Closure $callback): mixed
    {
        return $this->remember($key, null, $callback);
    }

    // ── Счётчики ───────────────────────────────────────────

    /**
     * Если ключа нет, он создаётся со значением $value (без срока жизни).
     * Срок жизни существующего ключа сохраняется. Нечисловое значение → false.
     */
    public function increment(string $key, int|float $value = 1): int|float|false
    {
        $entry = $this->entry($key);

        if ($entry === null) {
            return $this->writeIfAbsent($key, $value, null) ? $value : false;
        }

        if (!is_int($entry['value']) && !is_float($entry['value'])) {
            return false;
        }

        $new = $entry['value'] + $value;

        return $this->write($key, $new, $entry['expires']) ? $new : false;
    }

    public function decrement(string $key, int|float $value = 1): int|float|false
    {
        return $this->increment($key, -$value);
    }
}