<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Events;

use InvalidArgumentException;
use Neo\Information\Persistence\ORM\Model;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Создаёт объект события для #[Watch(Event::class)]. Параметры конструктора подбираются
 * по типу или имени, порядок не важен:
 *
 *     final class PriceChanged
 *     {
 *         public function __construct(public Product $product, public float $old, public float $new) {}
 *     }
 *
 * Модель: параметр с типом модели или имя `model`. Имена: `property`; `old`|`oldValue`|`previous`;
 * `new`|`newValue`|`value`|`current`.
 */
final class WatchEventFactory
{
    /** @var array<class-string, list<array{name: string, type: ?string, optional: bool, default: mixed}>> */
    private static array $plans = [];

    public static function make(string $class, Model $model, string $property, mixed $old, mixed $new): object
    {
        $args = [];

        foreach (self::$plans[$class] ??= self::plan($class) as $p) {
            $args[] = match (true) {
                $p['type'] !== null && $model instanceof $p['type'] => $model,
                $p['name'] === 'model' => $model,
                $p['name'] === 'property' => $property,
                in_array($p['name'], ['old', 'oldValue', 'previous'], true) => $old,
                in_array($p['name'], ['new', 'newValue', 'value', 'current'], true) => $new,
                $p['optional'] => $p['default'],
                default => throw new InvalidArgumentException(
                    "{$class}::__construct(): не знаю, что передать в \${$p['name']}. Допустимы: модель, \$property, \$old, \$new."
                ),
            };
        }

        return new $class(...$args);
    }

    private static function plan(string $class): array
    {
        $plan = [];

        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $param) {
            $type = $param->getType();

            $plan[] = [
                'name' => $param->getName(),
                'type' => $type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null,
                'optional' => $param->isOptional(),
                'default' => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
            ];
        }

        return $plan;
    }
}
