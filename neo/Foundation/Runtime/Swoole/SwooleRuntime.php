<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Swoole;

use Neo\Foundation\Runtime\AsyncIO;
use Neo\Foundation\Runtime\Capabilities;
use Neo\Foundation\Runtime\ChannelContract;
use Neo\Foundation\Runtime\ExecutionContext;
use Neo\Foundation\Runtime\RuntimeCapabilities;
use Neo\Foundation\Runtime\RuntimeContract;
use Neo\Foundation\Runtime\Sync\SyncChannel;
use Neo\Foundation\Runtime\Tasks\TaskDispatcher;
use RuntimeException;

/**
 * Swoole и OpenSwoole: процессы-воркеры, внутри каждого много корутин (один запрос = одна корутина).
 */
final class SwooleRuntime implements RuntimeContract
{
    private readonly CoroutineContext $context;

    private readonly SwooleAsyncIO $async;

    private readonly RuntimeCapabilities $capabilities;

    /** Сервер с task workers; null, пока они не включены. */
    private ?object $taskServer = null;

    private readonly CoroutineTaskDispatcher $coroutineTasks;

    public function __construct(?RuntimeCapabilities $capabilities = null)
    {
        $this->capabilities = $capabilities ?? Capabilities::swoole();

        if (!SwooleApi::available()) {
            throw new RuntimeException('Расширение swoole/openswoole не загружено.');
        }

        $this->context = new CoroutineContext();
        $this->async = new SwooleAsyncIO();
        $this->coroutineTasks = new CoroutineTaskDispatcher();
    }

    public static function isAvailable(): bool
    {
        return SwooleApi::available();
    }

    public function name(): string
    {
        return strtolower(SwooleApi::flavor());
    }

    public function capabilities(): RuntimeCapabilities
    {
        return $this->capabilities;
    }

    public function context(): ExecutionContext
    {
        return $this->context;
    }

    public function async(): AsyncIO
    {
        return $this->async;
    }

    public function executionId(): int
    {
        return max(0, SwooleApi::cid());
    }

    public function inCoroutine(): bool
    {
        return SwooleApi::cid() > 0;
    }

    public function defer(callable $callback): void
    {
        if ($this->inCoroutine()) {
            SwooleApi::defer($callback);

            return;
        }

        register_shutdown_function($callback);
    }

    /** Адаптер сервера сообщает, что у него есть task workers: задачи пойдут в них, а не в корутины. */
    public function attachTaskServer(?object $server): void
    {
        $this->taskServer = $server;
    }

    public function tick(float $seconds, callable $callback): int
    {
        return SwooleApi::tick($seconds, $callback);
    }

    public function clearTick(int $timerId): void
    {
        if ($timerId > 0) {
            SwooleApi::clearTimer($timerId);
        }
    }

    public function tasks(): TaskDispatcher
    {
        return $this->taskServer !== null ? new SwooleTaskDispatcher($this->taskServer) : $this->coroutineTasks;
    }

    public function channel(int $capacity = 1): ChannelContract
    {
        # Вне корутины Channel::pop() с ожиданием недоступен: очередь без ожидания безопаснее, чем зависание.
        return $this->inCoroutine() ? new SwooleChannel($capacity) : new SyncChannel($capacity);
    }
}
