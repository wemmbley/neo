<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Sync;

use Neo\Foundation\Runtime\AsyncIO;
use Neo\Foundation\Runtime\Capabilities;
use Neo\Foundation\Runtime\ChannelContract;
use Neo\Foundation\Runtime\ExecutionContext;
use Neo\Foundation\Runtime\RuntimeCapabilities;
use Neo\Foundation\Runtime\RuntimeContract;
use Neo\Foundation\Runtime\Tasks\TaskDispatcher;

/** Обычный синхронный PHP: FPM, Apache, `php -S`, консоль. Состояние живёт в процессе. */
final class SyncRuntime implements RuntimeContract
{
    private readonly ProcessContext $context;

    private readonly SyncAsyncIO $async;

    private readonly RuntimeCapabilities $capabilities;

    private readonly SyncTaskDispatcher $tasks;

    public function __construct(?RuntimeCapabilities $capabilities = null)
    {
        $this->context = new ProcessContext();
        $this->async = new SyncAsyncIO();
        $this->tasks = new SyncTaskDispatcher();
        $this->capabilities = $capabilities ?? Capabilities::sync();
    }

    public function name(): string
    {
        return 'sync';
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
        return 0;
    }

    public function inCoroutine(): bool
    {
        return false;
    }

    /** Процесс живёт один запрос, поэтому «конец исполнения» это конец процесса. */
    public function defer(callable $callback): void
    {
        register_shutdown_function($callback);
    }

    public function channel(int $capacity = 1): ChannelContract
    {
        return new SyncChannel($capacity);
    }

    public function tick(float $seconds, callable $callback): int
    {
        return 0;
    }

    public function clearTick(int $timerId): void {}

    public function tasks(): TaskDispatcher
    {
        return $this->tasks;
    }
}
