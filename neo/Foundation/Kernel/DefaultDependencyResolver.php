<?php

declare(strict_types=1);

namespace Neo\Foundation\Kernel;

use Closure;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;

class DefaultDependencyResolver implements DependencyResolverContract
{
    /**
     * Планы сборки классов: рефлексия делается один раз на класс за жизнь процесса.
     * Структура класса не меняется, поэтому кэш безопасен для Swoole и экономит
     * ReflectionClass и getParameters() на каждый make().
     *
     * @var array<class-string, array{instantiable: bool, constructor: bool, params: list<array<string, mixed>>}>
     */
    private static array $classPlans = [];

    /** @var array<string, array{ref: ReflectionMethod, params: list<array<string, mixed>>}> */
    private static array $methodPlans = [];

    /** @var array<string, bool> */
    private static array $instantiable = [];

    public function resolve(
        ServiceContainer $container,
        string           $abstract,
        array            $parameters = [],
    ): mixed
    {
        if ($container->hasInstance($abstract)) {
            return $container->getInstance($abstract);
        }

        if ($container->isResolving($abstract)) {
            throw new ServiceContainerException(
                'Циклическая зависимость: '
                . implode(' -> ', $container->getResolving())
                . ' -> ' . $abstract . '.'
            );
        }

        $binding = $container->getBinding($abstract);
        $concrete = $binding['concrete'] ?? $abstract;

        $container->startResolving($abstract);

        try {
            $object = match (true) {
                $concrete instanceof Closure => $concrete($container, $parameters),
                $concrete === $abstract => $this->build($container, $abstract, $parameters),
                default => $container->make($concrete, $parameters),
            };
        } finally {
            $container->stopResolving($abstract);
        }

        if ($binding !== null && $binding['shared']) {
            $container->storeInstance($abstract, $object);
        }

        return $object;
    }

    public function call(
        ServiceContainer      $container,
        callable|array|string $callback,
        array                 $parameters = [],
    ): mixed
    {
        if (is_string($callback) && str_contains($callback, '@')) {
            $callback = explode('@', $callback, 2);
        } elseif (
            is_string($callback)
            && !function_exists($callback)
            && class_exists($callback)
        ) {
            $callback = [$callback, '__invoke'];
        }

        if (is_array($callback)) {
            [$target, $method] = $callback;

            $object = is_string($target)
                ? $container->make($target)
                : $target;

            $key = $object::class . '::' . $method;
            $plan = self::$methodPlans[$key] ??= (static function () use ($object, $method): array {
                $reflection = new ReflectionMethod($object, $method);

                return ['ref' => $reflection, 'params' => self::plan($reflection->getParameters())];
            })();

            return $plan['ref']->invokeArgs(
                $object,
                $this->resolveParameters($container, $plan['params'], $parameters),
            );
        }

        if (is_object($callback) && !$callback instanceof Closure) {
            return $this->call(
                $container,
                [$callback, '__invoke'],
                $parameters,
            );
        }

        $closure = Closure::fromCallable($callback);
        $reflection = new ReflectionFunction($closure);

        return $closure(...$this->resolveParameters(
            $container,
            self::plan($reflection->getParameters()),
            $parameters,
        ));
    }

    private function build(
        ServiceContainer $container,
        string           $class,
        array            $parameters,
    ): object
    {
        $info = self::$classPlans[$class] ?? $this->inspect($class);

        if (!$info['instantiable']) {
            throw new ServiceContainerException(
                "Класс {$class} нельзя создать (абстрактный или с закрытым конструктором)."
            );
        }

        if (!$info['constructor']) {
            return new $class();
        }

        return new $class(...$this->resolveParameters($container, $info['params'], $parameters, $class));
    }

    /** @return array{instantiable: bool, constructor: bool, params: list<array<string, mixed>>} */
    private function inspect(string $class): array
    {
        if (!class_exists($class)) {
            throw new ServiceContainerException(
                interface_exists($class)
                    ? "Для интерфейса {$class} нет привязки: добавьте bind() или singleton()."
                    : "Класс {$class} не найден."
            );
        }

        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        return self::$classPlans[$class] = [
            'instantiable' => $reflection->isInstantiable(),
            'constructor' => $constructor !== null,
            'params' => $constructor !== null ? self::plan($constructor->getParameters()) : [],
        ];
    }

    /**
     * Описание параметров в виде простых массивов: дальше рефлексия не нужна.
     * Значения по умолчанию кэшируем, только если они не объекты (new в инициализаторе
     * должен давать свежий объект при каждой сборке).
     *
     * @param list<\ReflectionParameter> $parameters
     * @return list<array<string, mixed>>
     */
    private static function plan(array $parameters): array
    {
        $plan = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            $named = $type instanceof ReflectionNamedType ? $type : null;
            $hasDefault = $parameter->isDefaultValueAvailable();
            $default = $hasDefault ? $parameter->getDefaultValue() : null;

            $plan[] = [
                'name' => $parameter->getName(),
                'class' => $named !== null && !$named->isBuiltin() ? $named->getName() : null,
                'builtin' => $named !== null && $named->isBuiltin() ? $named->getName() : null,
                'hasDefault' => $hasDefault,
                'default' => is_object($default) ? null : $default,
                'dynamic' => is_object($default) ? $parameter : null,
                'nullable' => $type !== null && $type->allowsNull(),
                'variadic' => $parameter->isVariadic(),
            ];
        }

        return $plan;
    }

    /**
     * @param list<array<string, mixed>> $plan
     */
    private function resolveParameters(
        ServiceContainer $container,
        array            $plan,
        array            $given,
        string           $context = '',
    ): array
    {
        $arguments = [];

        foreach ($plan as $p) {
            $name = $p['name'];

            if ($given !== [] && array_key_exists($name, $given)) {
                $arguments[] = $this->coerce($given[$name], $p['builtin']);
                continue;
            }

            $class = $p['class'];

            if ($class !== null) {
                if ($given !== [] && array_key_exists($class, $given)) {
                    $arguments[] = $given[$class];
                    continue;
                }

                if ($container->has($class) || $this->isInstantiable($class)) {
                    $arguments[] = $container->make($class);
                    continue;
                }
            }

            if ($p['hasDefault']) {
                $arguments[] = $p['dynamic'] !== null ? $p['dynamic']->getDefaultValue() : $p['default'];
            } elseif ($p['nullable']) {
                $arguments[] = null;
            } elseif ($p['variadic']) {
                break;
            } else {
                $where = $context !== '' ? " в {$context}" : '';

                throw new ServiceContainerException(
                    "Не удалось подобрать значение для аргумента \${$name}{$where}."
                );
            }
        }

        return $arguments;
    }

    /** Можно ли собрать класс автоматически
     * (не абстрактный, не Closure, открытый конструктор).
     */
    private function isInstantiable(string $class): bool
    {
        return self::$instantiable[$class] ??= class_exists($class)
            && (new ReflectionClass($class))->isInstantiable();
    }

    private function coerce(
        mixed   $value,
        ?string $builtin,
    ): mixed
    {
        if (!is_string($value) || $builtin === null) {
            return $value;
        }

        return match ($builtin) {
            'int' => preg_match('/^-?\d+$/', $value) === 1
                ? (int)$value
                : $value,

            'float' => is_numeric($value)
                ? (float)$value
                : $value,

            default => $value,
        };
    }
}
