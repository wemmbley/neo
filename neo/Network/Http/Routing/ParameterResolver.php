<?php

declare(strict_types=1);

namespace Neo\Network\Http\Routing;

use BackedEnum;
use Closure;
use Neo\Foundation\Kernel\ServiceContainer;
use Neo\Information\Persistence\ORM\Exceptions\ModelNotFoundException;
use Neo\Information\Persistence\ORM\Model;
use Neo\Network\Http\Contracts\RequestContract;
use Neo\Network\Http\Exceptions\NotFoundHttpException;
use Neo\Network\Http\FormRequest;
use Neo\Network\Http\Request;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Подбирает аргументы для действия маршрута (замыкания или метода контроллера):
 *
 *  - Request / FormRequest        текущий запрос (FormRequest ещё и проверяется);
 *  - Route                        найденный маршрут;
 *  - параметры адреса по имени    `{post}` -> `$post`, с приведением int/float/bool;
 *  - Model / BackedEnum           неявная подстановка модели или enum по значению;
 *  - всё остальное                сервисы из контейнера.
 *
 * Не хранит состояния запроса: всё приходит аргументами.
 */
final class ParameterResolver
{
    public function __construct(
        private readonly ServiceContainer $container,
        private readonly Router $router,
    ) {}

    /**
     * @return list<mixed>
     */
    public function resolve(Route $route, Request $request, ReflectionFunctionAbstract $reflection): array
    {
        $parameters = $route->parameters();
        $arguments = [];

        foreach ($reflection->getParameters() as $parameter) {
            $name = $parameter->getName();
            $class = $this->classOf($parameter);

            if ($class !== null) {
                if (is_subclass_of($class, FormRequest::class)) {
                    $form = $class::createFrom($request);
                    $form->validateResolved();
                    $arguments[] = $form;
                    continue;
                }

                if ($class === Request::class || $class === RequestContract::class || $request instanceof $class) {
                    $arguments[] = $request;
                    continue;
                }

                if ($class === Route::class) {
                    $arguments[] = $route;
                    continue;
                }
            }

            if ($parameter->isVariadic()) {
                array_push($arguments, ...array_values($parameters));
                break;
            }

            if (array_key_exists($name, $parameters)) {
                $arguments[] = $this->bind($route, $name, $parameters[$name], $parameter, $class);
                unset($parameters[$name]);
                continue;
            }

            if ($class !== null && ($this->container->has($class) || $this->instantiable($class))) {
                $arguments[] = $this->container->make($class);
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } elseif ($parameter->allowsNull()) {
                $arguments[] = null;
            } else {
                throw new \LogicException(
                    'Не удалось подобрать значение для аргумента $' . $name . ' действия ' . $route->getActionName() . '.'
                );
            }
        }

        return $arguments;
    }

    /**
     * Заранее подставляет модели в параметры маршрута (для middleware, которым нужны модели,
     * например авторизация). Повторный вызов безопасен.
     */
    public function substitute(Route $route): void
    {
        $reflection = $this->reflect($route);

        if ($reflection === null) {
            return;
        }

        foreach ($reflection->getParameters() as $parameter) {
            $name = $parameter->getName();

            if ($route->hasParameter($name) && !is_object($route->parameter($name)) && $route->parameter($name) !== null) {
                $route->setParameter($name, $this->bind($route, $name, $route->parameter($name), $parameter, $this->classOf($parameter), true));
            }
        }

        # Явные биндеры работают и для параметров, которых нет в сигнатуре действия.
        foreach ($route->parameters() as $name => $value) {
            if (is_string($value) && $this->router->getBinder($name) !== null) {
                $route->setParameter($name, $this->router->getBinder($name)($value, $route));
            }
        }
    }

    public function reflect(Route $route): ?ReflectionFunctionAbstract
    {
        $uses = $route->getAction('uses');

        if ($uses instanceof Closure) {
            return new ReflectionFunction($uses);
        }

        [$class, $method] = $route->parseController();

        return class_exists($class) && method_exists($class, $method) ? new ReflectionMethod($class, $method) : null;
    }

    private function bind(Route $route, string $name, mixed $value, ReflectionParameter $parameter, ?string $class, bool $substituting = false): mixed
    {
        if (is_object($value) || $value === null) {
            return $value;
        }

        if (($binder = $this->router->getBinder($name)) !== null) {
            return $binder((string) $value, $route) ?? throw new NotFoundHttpException();
        }

        if ($class !== null && is_subclass_of($class, Model::class)) {
            $field = $route->bindingField($name);

            $model = $field === null
                ? $class::query()->find($value)
                : $class::query()->where($field, '=', $value)->first();

            return $model ?? throw new ModelNotFoundException($class, $value);
        }

        if ($class !== null && is_subclass_of($class, BackedEnum::class)) {
            return $class::tryFrom(is_numeric($value) && (new \ReflectionEnum($class))->getBackingType()?->getName() === 'int' ? (int) $value : $value)
                ?? throw new NotFoundHttpException();
        }

        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && $type->isBuiltin() && is_string($value)) {
            return match ($type->getName()) {
                'int' => preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : throw new NotFoundHttpException(),
                'float' => is_numeric($value) ? (float) $value : throw new NotFoundHttpException(),
                'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? throw new NotFoundHttpException(),
                default => $value,
            };
        }

        return $value;
    }

    private function classOf(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        return $type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
    }

    private function instantiable(string $class): bool
    {
        return class_exists($class) && (new \ReflectionClass($class))->isInstantiable();
    }
}
