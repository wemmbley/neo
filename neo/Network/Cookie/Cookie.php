<?php

declare(strict_types=1);

namespace Neo\Network\Cookie;

use Neo\Foundation\Kernel\Facade;
use Neo\Foundation\Kernel\FacadeOf;

/**
 * @method static mixed get(string $name, mixed $default = null)
 * @method static bool has(string $name)
 * @method static array all()
 * @method static void set(string $name, mixed $value, int $minutes = 0, array $options = [])
 * @method static void forever(string $name, mixed $value, array $options = [])
 * @method static void forget(string $name, array $options = [])
 * @method static void queue(CookieValue $cookie)
 *
 * @see CookieManager
 */
#[FacadeOf(CookieManager::class)]
final class Cookie extends Facade {}
