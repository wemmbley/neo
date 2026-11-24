<?php

declare(strict_types=1);

namespace Neo\Foundation\Pipeline;

use Closure;
use Neo\Foundation\Kernel\ServiceContainer;
use RuntimeException;
use Throwable;

/**
 * Конвейер (аналог Illuminate\Pipeline): пропускает объект через цепочку «слоёв».
 *
 *   (new Pipeline($container))
 *       ->send($request)
 *       ->through([FirstMiddleware::class, 'throttle:60,1', fn ($r, $next) => $next($r)])
 *       ->then(fn ($request) => $response);
 *
 * Слой: Closure, объект с методом `handle` (имя меняется через via()) или строка
 * `Класс:параметр1,параметр2` (создаётся контейнером, параметры идут после $next).
 *
 * Конвейер не хранит состояния между вызовами then(): один экземпляр нельзя
 * переиспользовать для параллельных запросов, создавайте новый на каждый запрос.
 */
class Pipeline
{
    protected mixed $passable = null;

    /** @var list<mixed> */
    protected array $pipes = [];

    protected string $method = 'handle';

    public function __construct(protected readonly ServiceContainer $container) {}

    public function send(mixed $passable): static
    {
        $this->passable = $passable;

        return $this;
    }

    public function through(mixed ...$pipes): static
    {
        $this->pipes = is_array($pipes[0] ?? null) && count($pipes) === 1 ? array_values($pipes[0]) : array_values($pipes);

        return $this;
    }

    public function pipe(mixed ...$pipes): static
    {
        array_push($this->pipes, ...(is_array($pipes[0] ?? null) && count($pipes) === 1 ? $pipes[0] : $pipes));

        return $this;
    }

    public function via(string $method): static
    {
        $this->method = $method;

        return $this;
    }

    public function then(Closure $destination): mixed
    {
        $pipeline = array_reduce(
            array_reverse($this->pipes),
            $this->carry(),
            function (mixed $passable) use ($destination): mixed {
                try {
                    return $destination($passable);
                } catch (Throwable $e) {
                    return $this->handleException($passable, $e);
                }
            },
        );

        return $pipeline($this->passable);
    }

    public function thenReturn(): mixed
    {
        return $this->then(static fn (mixed $passable): mixed => $passable);
    }

    protected function carry(): Closure
    {
        return fn (Closure $stack, mixed $pipe): Closure => function (mixed $passable) use ($stack, $pipe): mixed {
            try {
                if ($pipe instanceof Closure) {
                    return $pipe($passable, $stack);
                }

                if (is_object($pipe)) {
                    $parameters = [$passable, $stack];
                } else {
                    [$name, $parameters] = $this->parsePipeString((string) $pipe);
                    $pipe = $this->container->make($name);
                    $parameters = [$passable, $stack, ...$parameters];
                }

                if (!method_exists($pipe, $this->method)) {
                    throw new RuntimeException(
                        'Слой конвейера ' . $pipe::class . " не имеет метода {$this->method}()."
                    );
                }

                return $pipe->{$this->method}(...$parameters);
            } catch (Throwable $e) {
                return $this->handleException($passable, $e);
            }
        };
    }

    /**
     * Что делать с исключением внутри слоя. По умолчанию пробрасываем,
     * HTTP-конвейер превращает его в Response, чтобы внешние слои
     * (сессия, куки) успели отработать.
     */
    protected function handleException(mixed $passable, Throwable $e): mixed
    {
        throw $e;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    protected function parsePipeString(string $pipe): array
    {
        [$name, $parameters] = array_pad(explode(':', $pipe, 2), 2, null);

        return [$name, $parameters === null ? [] : explode(',', $parameters)];
    }
}
