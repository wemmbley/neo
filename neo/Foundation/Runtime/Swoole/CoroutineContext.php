<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Swoole;

use ArrayAccess;
use ArrayObject;
use Neo\Foundation\Runtime\ExecutionContext;

/**
 * Контекст корутины на `Coroutine::getContext()`. Данные исчезают вместе с корутиной.
 *
 * Без $cid объект всегда означает «текущую корутину» на момент вызова, поэтому один экземпляр можно
 * держать в рантайме. Вне корутин (master, boot воркера) данные лежат в обычном хранилище воркера.
 */
final class CoroutineContext implements ExecutionContext
{
    private readonly ArrayObject $fallback;

    public function __construct(private readonly ?int $cid = null)
    {
        $this->fallback = new ArrayObject();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $storage = $this->storage();

        return $storage->offsetExists($key) ? $storage[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->storage()[$key] = $value;
    }

    public function has(string $key): bool
    {
        return $this->storage()->offsetExists($key);
    }

    public function forget(string $key): void
    {
        $storage = $this->storage();

        if ($storage->offsetExists($key)) {
            unset($storage[$key]);
        }
    }

    public function parent(): ?ExecutionContext
    {
        $cid = $this->cid ?? SwooleApi::cid();

        if ($cid <= 0) {
            return null;
        }

        $parent = SwooleApi::pcid($cid);

        return $parent > 0 ? new self($parent) : null;
    }

    /** @return ArrayAccess<string, mixed>&\Countable */
    private function storage(): ArrayAccess
    {
        $cid = $this->cid ?? SwooleApi::cid();

        if ($cid > 0) {
            $context = SwooleApi::context($cid);

            if ($context !== null) {
                return $context;
            }
        }

        return $this->fallback;
    }
}
