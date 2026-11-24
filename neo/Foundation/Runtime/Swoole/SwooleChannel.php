<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Swoole;

use Neo\Foundation\Runtime\ChannelContract;

/** Обёртка над `Swoole\Coroutine\Channel` / `OpenSwoole\Coroutine\Channel`. */
final class SwooleChannel implements ChannelContract
{
    private readonly object $channel;

    public function __construct(int $capacity = 1)
    {
        $this->channel = SwooleApi::channel($capacity);
    }

    public function push(mixed $value, float $timeout = -1): bool
    {
        return (bool) $this->channel->push($value, $timeout);
    }

    public function pop(float $timeout = -1): mixed
    {
        return $this->channel->pop($timeout);
    }

    public function length(): int
    {
        return (int) $this->channel->length();
    }

    public function isEmpty(): bool
    {
        return (bool) $this->channel->isEmpty();
    }

    public function close(): void
    {
        $this->channel->close();
    }
}
