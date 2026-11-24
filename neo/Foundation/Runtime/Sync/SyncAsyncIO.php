<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Sync;

use Neo\Foundation\Runtime\AsyncIO;
use Neo\Foundation\Runtime\AsyncIoMode;
use Neo\Foundation\Runtime\Future;

/** AsyncIO без конкурентности: честный последовательный фасад. */
final class SyncAsyncIO implements AsyncIO
{
    public function mode(): AsyncIoMode
    {
        return AsyncIoMode::None;
    }

    public function run(callable $task): Future
    {
        return CompletedFuture::of($task);
    }

    public function all(array $tasks): array
    {
        $results = [];

        foreach ($tasks as $key => $task) {
            $results[$key] = $task();
        }

        return $results;
    }

    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }

    public function timeout(float $seconds, callable $task): mixed
    {
        return $task();
    }
}
