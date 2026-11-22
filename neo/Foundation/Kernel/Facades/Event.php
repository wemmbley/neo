<?php

declare(strict_types=1);

namespace Neo\Foundation\Kernel\Facades;

use Closure;
use Neo\Foundation\Kernel\Events\Dispatcher;
use Neo\Foundation\Kernel\FacadeOf;

/**
 * @method static void listen(string|array|Closure $events, string|array|Closure|null $listener = null)
 * @method static void subscribe(string|object $subscriber)
 * @method static mixed dispatch(string|object $event, mixed $payload = [], bool $halt = false)
 * @method static mixed until(string|object $event, mixed $payload = [])
 * @method static bool hasListeners(string $event)
 * @method static void forget(string $event)
 *
 * @see Dispatcher
 */
#[FacadeOf(Dispatcher::class)]
final class Event {}
