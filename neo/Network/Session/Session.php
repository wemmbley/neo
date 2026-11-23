<?php

declare(strict_types=1);

namespace Neo\Network\Session;

use Neo\Foundation\Kernel\Facade;
use Neo\Foundation\Kernel\FacadeOf;
use Neo\Network\Session\Contracts\SessionDriverContract;

/**
 * @method static mixed get(string $key, mixed $default = null)
 * @method static void put(string|array $key, mixed $value = null)
 * @method static void set(string|array $key, mixed $value = null)
 * @method static bool has(string $key)
 * @method static bool exists(string $key)
 * @method static bool missing(string $key)
 * @method static void forget(string|array $keys)
 * @method static void flush()
 * @method static mixed pull(string $key, mixed $default = null)
 * @method static void push(string $key, mixed $value)
 * @method static int increment(string $key, int $amount = 1)
 * @method static int decrement(string $key, int $amount = 1)
 * @method static void flash(string|array $key, mixed $value = null)
 * @method static mixed getFlash(string $key, mixed $default = null)
 * @method static void reflash()
 * @method static void keep(string|array $keys)
 * @method static string getId()
 * @method static bool isStarted()
 * @method static void start()
 * @method static void save()
 * @method static bool regenerate(bool $destroy = false)
 * @method static void invalidate()
 * @method static void destroy()
 * @method static array all()
 * @method static int count()
 * @method static SessionDriverContract driver()
 *
 * @see SessionService
 */
#[FacadeOf(SessionManager::class)]
final class Session extends Facade {}
