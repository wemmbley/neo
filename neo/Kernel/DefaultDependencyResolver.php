<?php

declare(strict_types=1);

namespace Neo\Kernel;

use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use Closure;

class DefaultDependencyResolver implements DependencyResolverContract
{
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

            $reflection = new ReflectionMethod($object, $method);

            return $reflection->invokeArgs(
                $object,
                $this->resolveParameters(
                    $container,
                    $reflection->getParameters(),
                    $parameters,
                ),
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
            $reflection->getParameters(),
            $parameters,
        ));
    }

    private function build(
        ServiceContainer $container,
        string           $class,
        array            $parameters,
    ): object
    {
        if (!class_exists($class)) {
            throw new ServiceContainerException(
                interface_exists($class)
                    ? "Для интерфейса {$class} нет привязки: добавьте bind() или singleton()."
                    : "Класс {$class} не найден."
            );
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ServiceContainerException(
                "Класс {$class} нельзя создать (абстрактный или с закрытым конструктором)."
            );
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        return $reflection->newInstanceArgs(
            $this->resolveParameters(
                $container,
                $constructor->getParameters(),
                $parameters,
                $class,
            ),
        );
    }

    private function resolveParameters(
        ServiceContainer $container,
        array            $reflections,
        array            $given,
        string           $context = '',
    ): array
    {
        $arguments = [];

        foreach ($reflections as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if (array_key_exists($name, $given)) {
                $arguments[] = $this->coerce($given[$name], $type);
                continue;
            }

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $class = $type->getName();

                if (array_key_exists($class, $given)) {
                    $arguments[] = $given[$class];
                    continue;
                }

                if ($container->has($class) || $this->isInstantiable($class)) {
                    $arguments[] = $container->make($class);
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
        return class_exists($class)
            && (new \ReflectionClass($class))->isInstantiable();
    }

    private function coerce(
        mixed           $value,
        ?ReflectionType $type,
    ): mixed
    {
        if (
            !is_string($value)
            || !$type instanceof ReflectionNamedType
            || !$type->isBuiltin()
        ) {
            return $value;
        }

        return match ($type->getName()) {
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