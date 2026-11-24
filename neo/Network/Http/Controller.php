<?php

declare(strict_types=1);

namespace Neo\Network\Http;

use BadMethodCallException;

/**
 * Базовый контроллер. Как в Laravel 12, наследоваться от него не обязательно:
 * контроллером может быть любой класс. Если базовый класс нужен для общих методов,
 * унаследуйте его и добавьте их в свой `App\Controllers\Controller`.
 *
 * Middleware контроллера объявляются через {@see Routing\Controllers\HasMiddleware}.
 * Контроллер создаётся заново на каждый запрос и не должен хранить данные между запросами.
 */
abstract class Controller
{
    /**
     * Роутер вызывает действие через callAction, если оно есть: удобная точка для общих
     * «до/после» в своих контроллерах.
     *
     * @param list<mixed> $parameters
     */
    public function callAction(string $method, array $parameters): mixed
    {
        return $this->{$method}(...$parameters);
    }

    public function __call(string $method, array $parameters): never
    {
        throw new BadMethodCallException('Метод ' . static::class . "::{$method}() не существует.");
    }
}
