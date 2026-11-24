<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Swoole;

use Neo\Foundation\Runtime\ChannelContract;
use Neo\Foundation\Runtime\Exceptions\TimeoutException;
use Neo\Foundation\Runtime\Future;
use Throwable;

/**
 * Результат задачи, запущенной в дочерней корутине. Ошибка не теряется: либо её получит тот, кто
 * вызвал await(), либо, если никто не дождался, она попадёт в error_log при уничтожении объекта.
 */
final class SwooleFuture implements Future
{
    private bool $done = false;

    private bool $observed = false;

    private mixed $value = null;

    private ?Throwable $error = null;

    public function __construct(
        private readonly ChannelContract $result,
        private readonly int $cid,
    ) {}

    public function await(?float $timeout = null): mixed
    {
        $this->observed = true;

        if (!$this->done) {
            $payload = $this->result->pop($timeout ?? -1);

            if ($payload === false) {
                SwooleApi::cancel($this->cid);

                throw new TimeoutException(
                    $timeout === null ? 'Канал результата закрыт.' : "Задача не завершилась за {$timeout} с."
                );
            }

            [$ok, $data] = $payload;
            $this->done = true;

            if ($ok) {
                $this->value = $data;
            } else {
                $this->error = $data;
            }
        }

        return $this->error !== null ? throw $this->error : $this->value;
    }

    public function isDone(): bool
    {
        return $this->done || !$this->result->isEmpty();
    }

    /** Отменяет корутину (если ещё работает). */
    public function cancel(): void
    {
        SwooleApi::cancel($this->cid);
    }

    public function __destruct()
    {
        if ($this->observed) {
            return;
        }

        $payload = $this->result->isEmpty() ? false : $this->result->pop(0.001);

        if (is_array($payload) && $payload[0] === false && $payload[1] instanceof Throwable) {
            error_log('[runtime] unobserved task failed: ' . $payload[1]::class . ': ' . $payload[1]->getMessage());
        }
    }
}
