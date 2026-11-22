<?php

declare(strict_types=1);

namespace Neo\Foundation\Kernel\Events;

use Closure;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionNamedType;

/**
 * Диспетчер событий.
 *
 * Событие это объект (имя = класс) или строка ('user.created', поддерживается 'user.*').
 * Слушатель: Closure, [класс|объект, метод], 'Класс@метод' или класс с handle()/__invoke().
 * Слушатель, вернувший false, останавливает распространение события.
 */
class Dispatcher
{
    /** @var array<string, list<Closure>> */
    private array $listeners = [];

    /** @var array<string, list<Closure>> */
    private array $wildcards = [];

    /** @var array<string, list<Closure>> */
    private array $wildcardCache = [];

    /** @param Closure(string): object|null $resolver создаёт слушателей (обычно контейнер) */
    public function __construct(private readonly ?Closure $resolver = null) {}

    /**
     * `listen(PriceChanged::class, Handler::class)` или `listen(fn (PriceChanged $e) => ...)`:
     * для голого Closure событие берётся из типа первого параметра.
     *
     * @param string|list<string>|Closure                $events
     * @param string|array{0: string|object, 1: string}|Closure|null $listener
     */
    public function listen(string|array|Closure $events, string|array|Closure|null $listener = null): void
    {
        if ($events instanceof Closure) {
            $listener = $events;
            $events = $this->eventFromSignature($events);
        }

        if ($listener === null) {
            throw new InvalidArgumentException('Для события не передан слушатель.');
        }

        foreach ((array) $events as $event) {
            if (str_contains($event, '*')) {
                $this->wildcards[$event][] = $this->makeListener($listener, true);
                $this->wildcardCache = [];
            } else {
                $this->listeners[$event][] = $this->makeListener($listener);
            }
        }
    }

    /** @param string|object $subscriber класс с методом subscribe(Dispatcher $events) */
    public function subscribe(string|object $subscriber): void
    {
        $this->resolve($subscriber)->subscribe($this);
    }

    /**
     * @param  string|object $event   объект события или имя
     * @param  mixed         $payload аргументы слушателей для строковых событий
     * @return list<mixed>|mixed      ответы слушателей; при $halt первый не-null ответ
     */
    public function dispatch(string|object $event, mixed $payload = [], bool $halt = false): mixed
    {
        [$name, $args] = is_object($event)
            ? [$event::class, [$event]]
            : [$event, is_array($payload) ? $payload : [$payload]];

        $responses = [];

        foreach ($this->getListeners($name) as $listener) {
            $response = $listener($name, $args);

            if ($halt && $response !== null) {
                return $response;
            }

            if ($response === false) {
                break;
            }

            $responses[] = $response;
        }

        return $halt ? null : $responses;
    }

    /** Вызывает слушателей до первого не-null ответа. */
    public function until(string|object $event, mixed $payload = []): mixed
    {
        return $this->dispatch($event, $payload, true);
    }

    public function hasListeners(string $event): bool
    {
        return $this->getListeners($event) !== [];
    }

    public function forget(string $event): void
    {
        unset($this->listeners[$event], $this->wildcards[$event]);
        $this->wildcardCache = [];
    }

    /** @return list<Closure> */
    public function getListeners(string $event): array
    {
        $listeners = $this->listeners[$event] ?? [];

        # Слушатели родительских классов и интерфейсов события тоже срабатывают.
        if (class_exists($event, false) || interface_exists($event, false)) {
            foreach ([...(class_parents($event, false) ?: []), ...(class_implements($event, false) ?: [])] as $type) {
                array_push($listeners, ...($this->listeners[$type] ?? []));
            }
        }

        return [...$listeners, ...($this->wildcardCache[$event] ??= $this->matchWildcards($event))];
    }

    /** @return list<Closure> */
    private function matchWildcards(string $event): array
    {
        $matched = [];

        foreach ($this->wildcards as $pattern => $listeners) {
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/u';

            if (preg_match($regex, $event) === 1) {
                array_push($matched, ...$listeners);
            }
        }

        return $matched;
    }

    private function makeListener(string|array|Closure $listener, bool $wildcard = false): Closure
    {
        if (!$listener instanceof Closure) {
            $listener = $this->classListener($listener);
        }

        # Обычные слушатели получают аргументы события, wildcard-слушатели ($имя, $аргументы).
        return $wildcard
            ? static fn (string $event, array $payload) => $listener($event, $payload)
            : static fn (string $event, array $payload) => $listener(...array_values($payload));
    }

    private function classListener(string|array $listener): Closure
    {
        [$target, $method] = match (true) {
            is_array($listener) => $listener,
            str_contains($listener, '@') => explode('@', $listener, 2),
            default => [$listener, 'handle'],
        };

        return function (mixed ...$args) use ($target, $method) {
            $instance = $this->resolve($target);

            if (!method_exists($instance, $method)) {
                $method = method_exists($instance, '__invoke')
                    ? '__invoke'
                    : throw new InvalidArgumentException($instance::class . " не имеет метода {$method}() или __invoke().");
            }

            return $instance->{$method}(...$args);
        };
    }

    private function resolve(string|object $class): object
    {
        if (is_object($class)) {
            return $class;
        }

        return $this->resolver !== null ? ($this->resolver)($class) : new $class();
    }

    private function eventFromSignature(Closure $listener): string
    {
        $type = (new ReflectionFunction($listener))->getParameters()[0]?->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            throw new InvalidArgumentException('Первый параметр Closure должен быть типизирован классом события.');
        }

        return $type->getName();
    }
}
