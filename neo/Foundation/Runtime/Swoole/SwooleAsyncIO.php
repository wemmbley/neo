<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Swoole;

use Neo\Foundation\Runtime\AsyncIO;
use Neo\Foundation\Runtime\AsyncIoMode;
use Neo\Foundation\Runtime\Future;
use Throwable;

/** AsyncIO на корутинах: задачи выполняются конкурентно, ввод-вывод не блокирует воркер. */
final class SwooleAsyncIO implements AsyncIO
{
    public function mode(): AsyncIoMode
    {
        return AsyncIoMode::Coroutines;
    }

    public function run(callable $task): Future
    {
        $result = new SwooleChannel(1);

        $cid = SwooleApi::create(static function () use ($task, $result): void {
            try {
                $result->push([true, $task()]);
            } catch (Throwable $e) {
                $result->push([false, $e]);
            }
        });

        return new SwooleFuture($result, $cid);
    }

    public function all(array $tasks): array
    {
        $futures = [];

        foreach ($tasks as $key => $task) {
            $futures[$key] = $this->run($task);
        }

        $results = [];

        try {
            foreach ($futures as $key => $future) {
                $results[$key] = $future->await();
            }
        } catch (Throwable $e) {
            # Структурированная конкурентность: упала одна, остальные отменяем и не оставляем сирот.
            foreach ($futures as $future) {
                if ($future instanceof SwooleFuture && !$future->isDone()) {
                    $future->cancel();
                }
            }

            throw $e;
        }

        return $results;
    }

    public function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        SwooleApi::inCoroutine() ? SwooleApi::sleep($seconds) : usleep((int) round($seconds * 1_000_000));
    }

    public function timeout(float $seconds, callable $task): mixed
    {
        return $this->run($task)->await($seconds);
    }
}
