<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Sync;

use Neo\Foundation\Runtime\ChannelContract;
use SplQueue;

/** Очередь без ожидания: нечему переключаться, поэтому pop() пустого канала сразу даёт false. */
final class SyncChannel implements ChannelContract
{
    /** @var SplQueue<mixed> */
    private SplQueue $queue;

    private bool $closed = false;

    public function __construct(private readonly int $capacity = 1)
    {
        $this->queue = new SplQueue();
    }

    public function push(mixed $value, float $timeout = -1): bool
    {
        if ($this->closed || $this->queue->count() >= max(1, $this->capacity)) {
            return false;
        }

        $this->queue->enqueue($value);

        return true;
    }

    public function pop(float $timeout = -1): mixed
    {
        return $this->queue->isEmpty() ? false : $this->queue->dequeue();
    }

    public function length(): int
    {
        return $this->queue->count();
    }

    public function isEmpty(): bool
    {
        return $this->queue->isEmpty();
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
