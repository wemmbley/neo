<?php


declare(strict_types=1);

namespace Neo\Kernel;

use Throwable;

class ProfilerDependencyResolver implements DependencyResolverContract
{
    private array $statistics = [];

    private int $depth = 0;

    private int $peakMemory = 0;

    public function __construct(
        private readonly DependencyResolverContract $resolver,
    ){}

    public function resolve(
        ServiceContainer $container,
        string           $abstract,
        array            $parameters = [],
    ): mixed
    {
        return $this->profile(
            'resolve',
            $abstract,
            fn() => $this->resolver->resolve(
                $container,
                $abstract,
                $parameters,
            ),
        );
    }

    public function call(
        ServiceContainer      $container,
        callable|array|string $callback,
        array                 $parameters = [],
    ): mixed
    {
        return $this->profile(
            'call',
            $this->getCallbackName($callback),
            fn() => $this->resolver->call(
                $container,
                $callback,
                $parameters,
            ),
        );
    }

    private function profile(
        string   $operation,
        string   $name,
        callable $callback,
    ): mixed
    {
        $startTime = hrtime(true);
        $startMemory = memory_get_usage(true);

        $this->depth++;

        $this->peakMemory = max(
            $this->peakMemory,
            memory_get_peak_usage(true),
        );

        $success = false;

        try {
            $result = $callback();
            $success = true;

            return $result;
        } finally {
            $elapsed = (hrtime(true) - $startTime) / 1_000_000;
            $memory = memory_get_usage(true) - $startMemory;

            $this->depth--;

            $this->record(
                $operation,
                $name,
                $elapsed,
                $memory,
                $success,
            );
        }
    }

    private function record(
        string $operation,
        string $name,
        float  $time,
        int    $memory,
        bool   $success,
    ): void
    {
        $this->statistics['total_resolutions'] =
            ($this->statistics['total_resolutions'] ?? 0) + ($operation === 'resolve' ? 1 : 0);

        $this->statistics['total_calls'] =
            ($this->statistics['total_calls'] ?? 0) + ($operation === 'call' ? 1 : 0);

        $this->statistics['successful_resolutions'] =
            ($this->statistics['successful_resolutions'] ?? 0) + ($success ? 1 : 0);

        $this->statistics['failed_resolutions'] =
            ($this->statistics['failed_resolutions'] ?? 0) + (!$success ? 1 : 0);

        $this->statistics['total_time'] =
            ($this->statistics['total_time'] ?? 0.0) + $time;

        $this->statistics['max_time'] = max(
            $this->statistics['max_time'] ?? 0.0,
            $time,
        );

        $this->statistics['total_memory_delta'] =
            ($this->statistics['total_memory_delta'] ?? 0) + $memory;

        $this->statistics['peak_memory'] = max(
            $this->statistics['peak_memory'] ?? 0,
            $this->peakMemory,
        );

        $this->statistics['max_depth'] = max(
            $this->statistics['max_depth'] ?? 0,
            $this->depth + 1,
        );

        $this->statistics['classes'][$name] ??= [
            'count' => 0,
            'success' => 0,
            'failed' => 0,
            'total_time' => 0.0,
            'max_time' => 0.0,
        ];

        $class = &$this->statistics['classes'][$name];

        $class['count']++;
        $class['success'] += $success ? 1 : 0;
        $class['failed'] += $success ? 0 : 1;
        $class['total_time'] += $time;
        $class['max_time'] = max($class['max_time'], $time);

        unset($class);
    }

    private function getCallbackName(
        callable|array|string $callback,
    ): string
    {
        if (is_array($callback)) {
            $target = $callback[0];

            $class = is_object($target)
                ? $target::class
                : $target;

            return $class . '@' . $callback[1];
        }

        if (is_string($callback)) {
            return $callback;
        }

        return is_object($callback)
            ? $callback::class
            : 'Closure';
    }

    public function getStatistics(): array
    {
        $statistics = array_replace([
            'total_resolutions' => 0,
            'total_calls' => 0,

            'successful_resolutions' => 0,
            'failed_resolutions' => 0,

            'total_time' => 0.0,
            'average_time' => 0.0,
            'max_time' => 0.0,

            'total_memory_delta' => 0,
            'peak_memory' => 0,
            'max_depth' => 0,

            'classes' => [],
        ], $this->statistics);

        $count = $statistics['total_resolutions'];

        $statistics['average_time'] = $count > 0
            ? $statistics['total_time'] / $count
            : 0.0;

        return $statistics;
    }

    public function reset(): void
    {
        $this->statistics = [];
        $this->depth = 0;
        $this->peakMemory = 0;
    }
}