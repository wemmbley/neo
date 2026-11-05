<?php

declare(strict_types=1);

namespace Neo\Kernel;

use LogicException;
use ReflectionClass;

abstract class Facade
{
    /** @var array<class-string, string> */
    private static array $targets = [];

    final public static function __callStatic(string $method, array $args): mixed
    {
        return Application::getInstance()
            ->make(self::targetOf(static::class))
            ->$method(...$args);
    }

    private static function targetOf(string $facade): string
    {
        if (isset(self::$targets[$facade])) {
            return self::$targets[$facade];
        }

        $attribute = (new ReflectionClass($facade))
            ->getAttributes(FacadeOf::class)[0] ?? null;

        if ($attribute === null) {
            throw new LogicException(
                "У фасада {$facade} нет атрибута #[FacadeOf]."
            );
        }

        return self::$targets[$facade] = $attribute->newInstance()->abstract;
    }
}