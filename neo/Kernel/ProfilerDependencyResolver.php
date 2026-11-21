<?php

declare(strict_types=1);

namespace Neo\Kernel;

/**
 * Обёртка над любым резолвером, собирающая статистику.
 *
 * Время считается двумя способами:
 *  - inclusive: вместе с вложенными зависимостями;
 *  - self: только собственная работа (inclusive минус вложенные).
 * Общее время (total_time) складывается из self, поэтому
 * вложенные операции не считаются дважды.
 */
class ProfilerDependencyResolver implements DependencyResolverContract
{
    /** Сколько операций максимум попадёт в trace (защита памяти). */
    private const int TRACE_LIMIT = 500;

    private int $resolutions = 0;

    private int $calls = 0;

    private int $successful = 0;

    private int $failed = 0;

    private float $totalTime = 0.0;

    private float $maxTime = 0.0;

    private int $memoryDelta = 0;

    private int $maxDepth = 0;

    /**
     * Статистика по именам.
     *
     * @var array<string, array{count:int, success:int, failed:int, total_time:float, self_time:float, max_time:float}>
     */
    private array $classes = [];

    /**
     * Операции в порядке начала (родитель раньше детей), depth даёт дерево.
     *
     * @var list<array{operation:string, name:string, depth:int, time:float, self_time:float, memory:int, success:bool}>
     */
    private array $trace = [];

    /**
     * Стек открытых операций: для каждой копится время вложенных.
     *
     * @var list<float>
     */
    private array $frames = [];

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
        $depth = count($this->frames);

        $this->frames[] = 0.0;
        $this->maxDepth = max($this->maxDepth, $depth + 1);

        # Место в trace резервируем сразу, чтобы родитель стоял перед детьми.
        #
        $slot = null;

        if (count($this->trace) < self::TRACE_LIMIT) {
            $this->trace[] = null;
            $slot = array_key_last($this->trace);
        }

        # false: реальные байты, а не блоки по 2 МБ (иначе дельта почти всегда 0).
        #
        $startMemory = memory_get_usage(false);
        $startTime = hrtime(true);
        $success = false;

        try {
            $result = $callback();
            $success = true;

            return $result;
        } finally {
            $elapsed = (hrtime(true) - $startTime) / 1_000_000;
            $memory = memory_get_usage(false) - $startMemory;

            $nested = array_pop($this->frames);

            # Своё время родителю не принадлежит: плюсуем его ему во «вложенные».
            #
            if ($this->frames !== []) {
                $this->frames[array_key_last($this->frames)] += $elapsed;
            }

            $this->record(
                $operation,
                $name,
                $depth,
                $slot,
                $elapsed,
                max(0.0, $elapsed - $nested),
                $memory,
                $success,
            );
        }
    }

    private function record(
        string $operation,
        string $name,
        int    $depth,
        ?int   $slot,
        float  $time,
        float  $selfTime,
        int    $memory,
        bool   $success,
    ): void
    {
        $operation === 'resolve' ? $this->resolutions++ : $this->calls++;
        $success ? $this->successful++ : $this->failed++;

        $this->totalTime += $selfTime;
        $this->maxTime = max($this->maxTime, $time);
        $this->memoryDelta += $memory;

        $this->classes[$name] ??= [
            'count' => 0,
            'success' => 0,
            'failed' => 0,
            'total_time' => 0.0,
            'self_time' => 0.0,
            'max_time' => 0.0,
        ];

        $class = &$this->classes[$name];

        $class['count']++;
        $class['success'] += $success ? 1 : 0;
        $class['failed'] += $success ? 0 : 1;
        $class['total_time'] += $time;
        $class['self_time'] += $selfTime;
        $class['max_time'] = max($class['max_time'], $time);

        unset($class);

        if ($slot !== null) {
            $this->trace[$slot] = [
                'operation' => $operation,
                'name' => $name,
                'depth' => $depth,
                'time' => $time,
                'self_time' => $selfTime,
                'memory' => $memory,
                'success' => $success,
            ];
        }
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

    /**
     * Времена в миллисекундах, память в байтах.
     *
     * total_time    сумма self-времени всех операций (без двойного счёта);
     * average_time  total_time / число операций (resolve + call);
     * max_time      самая долгая операция, вместе с вложенными;
     * peak_memory   пик процесса на момент вызова.
     */
    public function getStatistics(): array
    {
        $operations = $this->resolutions + $this->calls;

        return [
            'total_resolutions' => $this->resolutions,
            'total_calls' => $this->calls,

            'successful_resolutions' => $this->successful,
            'failed_resolutions' => $this->failed,

            'total_time' => $this->totalTime,
            'average_time' => $operations > 0
                ? $this->totalTime / $operations
                : 0.0,
            'max_time' => $this->maxTime,

            'total_memory_delta' => $this->memoryDelta,
            'peak_memory' => memory_get_peak_usage(true),
            'max_depth' => $this->maxDepth,

            'classes' => $this->classes,
        ];
    }

    /**
     * Операции в порядке запуска; depth показывает вложенность.
     *
     * @return list<array{operation:string, name:string, depth:int, time:float, self_time:float, memory:int, success:bool}>
     */
    public function getTrace(): array
    {
        return $this->trace;
    }

    /**
     * Самые «дорогие» сервисы по собственному времени.
     *
     * @return array<string, array<string, int|float>>
     */
    public function getSlowest(int $limit = 10): array
    {
        $classes = $this->classes;

        uasort(
            $classes,
            static fn (array $a, array $b): int => $b['self_time'] <=> $a['self_time'],
        );

        return array_slice($classes, 0, $limit, true);
    }

    public function reset(): void
    {
        $this->resolutions = 0;
        $this->calls = 0;
        $this->successful = 0;
        $this->failed = 0;
        $this->totalTime = 0.0;
        $this->maxTime = 0.0;
        $this->memoryDelta = 0;
        $this->maxDepth = 0;
        $this->classes = [];
        $this->trace = [];
        $this->frames = [];
    }
}