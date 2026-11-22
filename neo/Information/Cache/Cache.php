<?php

namespace Neo\Information\Cache;

use DateInterval;
use DateTimeInterface;
use Neo\Information\Cache\Contracts\CacheStoreContract;
use Neo\Information\Cache\Managers\CacheManager;
use Neo\Foundation\Kernel\Facade;
use Neo\Foundation\Kernel\FacadeOf;

/**
 * @method static string getDefaultStore()
 * @method static CacheStoreContract build(array $config)
 * @method static CacheStoreContract fake(?string $name = null)
 * @method static static extend(string $driver, \Closure $factory)
 * @method static void purge(?string $name = null)
 * @method static mixed get(string $key, mixed $default = null)
 * @method static array many(array $keys)
 * @method static bool has(string|array $key)
 * @method static bool put(string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null)
 * @method static bool putMany(array $values, DateTimeInterface|DateInterval|int|null $ttl = null)
 * @method static bool forget(string|array $key)
 * @method static mixed remember(string $key, DateTimeInterface|DateInterval|int|null $ttl, \Closure $callback)
 * @method static mixed rememberForever(string $key, \Closure $callback)
 * @method static bool forever(string $key, mixed $value)
 * @method static bool flush()
 * @method static bool add(string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null)
 * @method static mixed pull(string $key, mixed $default = null)
 * @method static int|float|false increment(string $key, int|float $value = 1)
 * @method static int|float|false decrement(string $key, int|float $value = 1)
 */
#[FacadeOf(CacheManager::class)]
class Cache extends Facade {}