<?php

declare(strict_types=1);

namespace Neo\Cache\Managers;

use Closure;
use DateInterval;
use DateTimeInterface;
use Neo\Cache\Contracts\CacheStoreContract;
use Neo\Cache\Exceptions\CacheNotConfiguredException;
use Neo\Cache\Stores\ArrayStore;
use Neo\Cache\Stores\FileStore;
use Neo\Cache\Transfers\CacheTransfer;
use Neo\Storage\Contracts\StorageManagerContract;

/**
 * Создаёт и кеширует хранилища. Сам реализует CacheStoreContract и
 * проксирует вызовы в хранилище по умолчанию, поэтому Cache::get() работает напрямую,
 * а Cache::store('file')->get() выбирает конкретный стор.
 *
 * Конфиг (CacheTransfer):
 * new CacheTransfer([
 *   'default' => 'file',
 *   'prefix'  => 'neo',
 *   'stores'  => [
 *     'array' => ['driver' => 'array', 'serialize' => true],
 *     'file'  => ['driver' => 'file', 'disk' => 'local', 'directory' => 'cache'],
 *   ],
 * ])
 *
 * Конфиг отдельного стора остаётся массивом: набор ключей зависит от драйвера.
 * Ключ prefix стора (если задан) перекрывает общий префикс.
 */
class CacheManager implements CacheStoreContract
{
    /** @var array<string, CacheStoreContract> */
    private array $stores = [];

    /** @var array<string, Closure(array): CacheStoreContract> */
    private array $extensions = [];

    public function __construct(
        private readonly CacheTransfer $config,
        private readonly ?StorageManagerContract $storage = null,
    ) {}

    // ── Управление хранилищами ─────────────────────────────

    public function getDefaultStore(): string
    {
        return $this->config->default;
    }

    public function store(?string $name = null): CacheStoreContract
    {
        $name ??= $this->getDefaultStore();

        return $this->stores[$name] ??= $this->resolve($name);
    }

    /**
     * Создать стор на лету, не регистрируя его.
     */
    public function build(array $config): CacheStoreContract
    {
        return $this->make($config);
    }

    /**
     * Добавить драйвер. Фабрика получает конфиг стора и возвращает CacheStoreContract.
     */
    public function extend(string $driver, Closure $factory): static
    {
        $this->extensions[$driver] = $factory;

        return $this;
    }

    /**
     * Подменить стор in-memory копией (для тестов).
     */
    public function fake(?string $name = null): CacheStoreContract
    {
        $name ??= $this->getDefaultStore();

        return $this->stores[$name] = new ArrayStore($this->config->prefix);
    }

    /**
     * Сбросить закешированный экземпляр стора (пересоздастся при следующем обращении).
     */
    public function purge(?string $name = null): void
    {
        unset($this->stores[$name ?? $this->getDefaultStore()]);
    }

    private function resolve(string $name): CacheStoreContract
    {
        $config = $this->config->stores[$name] ?? null;

        if (!is_array($config)) {
            throw new CacheNotConfiguredException("Cache store [{$name}] is not configured.");
        }

        return $this->make($config, $name);
    }

    private function make(array $config, string $name = '(inline)'): CacheStoreContract
    {
        $driver = $config['driver'] ?? null;

        if (!is_string($driver) || $driver === '') {
            throw new CacheNotConfiguredException("Cache store [{$name}] has no driver.");
        }

        $config['prefix'] ??= $this->config->prefix;

        if (isset($this->extensions[$driver])) {
            $store = ($this->extensions[$driver])($config);

            if (!$store instanceof CacheStoreContract) {
                throw new CacheNotConfiguredException(
                    "Custom cache driver [{$driver}] must return " . CacheStoreContract::class . '.'
                );
            }

            return $store;
        }

        return match ($driver) {
            'array' => new ArrayStore((string) $config['prefix'], (bool) ($config['serialize'] ?? true)),
            'file' => $this->makeFileStore($config, $name),
            default => throw new CacheNotConfiguredException("Cache driver [{$driver}] is not supported (store [{$name}])."),
        };
    }

    private function makeFileStore(array $config, string $name): FileStore
    {
        if ($this->storage === null) {
            throw new CacheNotConfiguredException(
                "Cache store [{$name}] uses the file driver, but no StorageManager was passed to CacheManager."
            );
        }

        $disk = $this->storage->disk(isset($config['disk']) ? (string) $config['disk'] : null);

        return new FileStore($disk, (string) ($config['directory'] ?? 'cache'), (string) $config['prefix']);
    }

    // ── Проксирование в стор по умолчанию ──────────────────

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store()->get($key, $default);
    }

    public function many(array $keys): array
    {
        return $this->store()->many($keys);
    }

    public function has(string|array $key): bool
    {
        return $this->store()->has($key);
    }

    public function put(string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null): bool
    {
        return $this->store()->put($key, $value, $ttl);
    }

    public function putMany(array $values, DateTimeInterface|DateInterval|int|null $ttl = null): bool
    {
        return $this->store()->putMany($values, $ttl);
    }

    public function forget(string|array $key): bool
    {
        return $this->store()->forget($key);
    }

    public function remember(string $key, DateTimeInterface|DateInterval|int|null $ttl, Closure $callback): mixed
    {
        return $this->store()->remember($key, $ttl, $callback);
    }

    public function rememberForever(string $key, Closure $callback): mixed
    {
        return $this->store()->rememberForever($key, $callback);
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->store()->forever($key, $value);
    }

    public function flush(): bool
    {
        return $this->store()->flush();
    }

    public function add(string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null): bool
    {
        return $this->store()->add($key, $value, $ttl);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        return $this->store()->pull($key, $default);
    }

    public function increment(string $key, int|float $value = 1): int|float|false
    {
        return $this->store()->increment($key, $value);
    }

    public function decrement(string $key, int|float $value = 1): int|float|false
    {
        return $this->store()->decrement($key, $value);
    }
}