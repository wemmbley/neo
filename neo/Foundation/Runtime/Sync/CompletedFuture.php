<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Sync;

use Neo\Foundation\Runtime\Future;
use Throwable;

/** Уже выполненная задача (режим AsyncIoMode::None). Исключение хранится до await(). */
final class CompletedFuture implements Future
{
    private function __construct(
        private readonly mixed $value,
        private readonly ?Throwable $error,
    ) {}

    public static function of(callable $task): self
    {
        try {
            return new self($task(), null);
        } catch (Throwable $e) {
            return new self(null, $e);
        }
    }

    public function await(?float $timeout = null): mixed
    {
        return $this->error !== null ? throw $this->error : $this->value;
    }

    public function isDone(): bool
    {
        return true;
    }
}
