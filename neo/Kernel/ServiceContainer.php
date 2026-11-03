<?php

declare(strict_types=1);

namespace Neo\Kernel;

/**
 * Контейнер зависимостей: хранит привязки, создаёт
 * объекты и сам подставляет аргументы конструкторов
 * и методов по типам.
 *
 *   $container->singleton(Mailer::class, fn (Container $container) => new Mailer($container->make(Config::class)));
 *   $container->make(UserService::class);               // зависимости подставятся сами
 *   $container->call([UserController::class, 'show'], ['id' => 5]);
 *
 * Аргумент подбирается так: сначала по имени из переданного
 * массива, затем по классу (из массива или контейнера), затем
 * значение по умолчанию, затем null (если тип допускает).
 * Строки вида "5" приводятся к int/float, если метод этого
 * требует (параметры маршрутов).
 */
class ServiceContainer
{
    /** @var array<string, array{concrete: \Closure|string|null, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $resolving = [];

    /**
     * Привязывает имя к способу создания.
     * Каждый make() создаёт новый объект.
     *
     * @param \Closure|string|null $concrete
     * Замыкание `fn (Container $c, array $params)`,
     * имя класса или null (создавать сам $abstract).
     */
    public function bind(string $abstract, \Closure|string|null $concrete = null, bool $shared = false): static
    {
        unset($this->instances[$abstract]);
        $this->bindings[$abstract] = ['concrete' => $concrete, 'shared' => $shared];

        return $this;
    }

    /**
     * Как bind(), но объект создаётся один раз и переиспользуется.
     */
    public function singleton(string $abstract, \Closure|string|null $concrete = null): static
    {
        return $this->bind($abstract, $concrete, true);
    }

    /**
     * Кладёт готовый объект (или значение) под именем.
     */
    public function instance(string $abstract, mixed $instance): mixed
    {
        unset($this->bindings[$abstract]);

        return $this->instances[$abstract] = $instance;
    }

    /**
     * Убирает привязку и готовый объект:
     * дальше имя считается неизвестным.
     */
    public function forget(string $abstract): void
    {
        unset($this->bindings[$abstract], $this->instances[$abstract]);
    }

    /**
     * Известно ли контейнеру это имя (привязка или готовый объект).
     */
    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || array_key_exists($abstract, $this->instances);
    }

    /**
     * Создаёт или возвращает объект.
     *
     * @param array<string, mixed> $parameters
     * Значения для аргументов конструктора по имени.
     *
     * @throws ServiceContainerException Если объект создать
     * нельзя или найден цикл зависимостей.
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        if (array_key_exists($abstract, $this->instances)) {
            return $this->instances[$abstract];
        }

        if (isset($this->resolving[$abstract])) {
            throw new ServiceContainerException(
                'Циклическая зависимость: ' . implode(' -> ', array_keys($this->resolving)) . ' -> ' . $abstract . '.'
            );
        }

        $binding = $this->bindings[$abstract] ?? null;
        $concrete = $binding['concrete'] ?? $abstract;

        $this->resolving[$abstract] = true;

        try {
            $object = match (true) {
                $concrete instanceof \Closure => $concrete($this, $parameters),
                $concrete === $abstract => $this->build($abstract, $parameters),
                default => $this->make($concrete, $parameters),
            };
        } finally {
            unset($this->resolving[$abstract]);
        }

        if ($binding !== null && $binding['shared']) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /**
     * То же, что make(); для совместимости с привычным
     * интерфейсом контейнеров.
     */
    public function get(string $id): mixed
    {
        return $this->make($id);
    }

    /**
     * Вызывает функцию или метод, подставляя аргументы
     * так же, как make().
     *
     * Принимает замыкание, функцию,
     * `[класс|объект, 'метод']`, `'Класс@метод'`,
     * имя класса или объект с `__invoke`.
     *
     * @param array<string, mixed> $parameters
     * Значения по имени аргумента или по имени класса.
     * @throws \ReflectionException
     */
    public function call(callable|array|string $callback, array $parameters = []): mixed
    {
        if (is_string($callback) && str_contains($callback, '@')) {
            $callback = explode('@', $callback, 2);
        } elseif (is_string($callback) && !function_exists($callback) && class_exists($callback)) {
            $callback = [$callback, '__invoke'];
        }

        if (is_array($callback)) {
            [$target, $method] = $callback;
            $object = is_string($target) ? $this->make($target) : $target;
            $reflection = new \ReflectionMethod($object, $method);

            return $reflection->invokeArgs($object, $this->resolveParameters($reflection->getParameters(), $parameters));
        }

        if (is_object($callback) && !$callback instanceof \Closure) {
            return $this->call([$callback, '__invoke'], $parameters);
        }

        $closure = \Closure::fromCallable($callback);
        $reflection = new \ReflectionFunction($closure);

        return $closure(...$this->resolveParameters($reflection->getParameters(), $parameters));
    }

    /**
     * @param array<string, mixed> $parameters
     * @throws \ReflectionException
     */
    private function build(string $class, array $parameters): object
    {
        if (!class_exists($class)) {
            throw new ServiceContainerException(
                interface_exists($class)
                    ? "Для интерфейса {$class} нет привязки: добавьте bind() или singleton()."
                    : "Класс {$class} не найден."
            );
        }

        $reflection = new \ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ServiceContainerException("Класс {$class} нельзя создать (абстрактный или с закрытым конструктором).");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        return $reflection->newInstanceArgs($this->resolveParameters($constructor->getParameters(), $parameters, $class));
    }

    /**
     * @param list<\ReflectionParameter> $reflections
     * @param array<string, mixed>       $given
     *
     * @return list<mixed>
     */
    private function resolveParameters(array $reflections, array $given, string $context = ''): array
    {
        $arguments = [];

        foreach ($reflections as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if (array_key_exists($name, $given)) {
                $arguments[] = $this->coerce($given[$name], $type);
                continue;
            }

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $class = $type->getName();

                if (array_key_exists($class, $given)) {
                    $arguments[] = $given[$class];
                    continue;
                }

                if ($this->has($class) || class_exists($class)) {
                    $arguments[] = $this->make($class);
                    continue;
                }
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } elseif ($type !== null && $type->allowsNull()) {
                $arguments[] = null;
            } elseif ($parameter->isVariadic()) {
                break;
            } else {
                $where = $context !== '' ? " в {$context}" : '';

                throw new ServiceContainerException("Не удалось подобрать значение для аргумента \${$name}{$where}.");
            }
        }

        return $arguments;
    }

    /**
     * Приводит строку из URL к числу, если метод ждёт int или float.
     */
    private function coerce(mixed $value, ?\ReflectionType $type): mixed
    {
        if (!is_string($value) || !$type instanceof \ReflectionNamedType || !$type->isBuiltin()) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $value,
            'float' => is_numeric($value) ? (float) $value : $value,
            default => $value,
        };
    }
}
