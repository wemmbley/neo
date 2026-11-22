<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Casts;

use BackedEnum;
use DateTimeInterface;
use JsonSerializable;
use Stringable;

/**
 * Преобразование значений: из БД в PHP-тип свойства и обратно в скаляр для записи.
 */
final class Caster
{
    public static function fromDb(mixed $value, ?string $cast): mixed
    {
        if ($value === null || $cast === null) {
            return $value;
        }

        $name = strtolower($cast);

        if (str_starts_with($name, 'decimal:')) {
            return (float) $value;
        }

        return match ($name) {
            'int', 'integer' => (int) $value,
            'float', 'double', 'real' => (float) $value,
            'string' => (string) $value,
            'bool', 'boolean' => is_string($value) ? !in_array(strtolower($value), ['', '0', 'false'], true) : (bool) $value,
            'array', 'json' => is_string($value) ? (array) json_decode($value, true) : (array) $value,
            'object' => is_string($value) ? json_decode($value) : (object) $value,
            'datetime', 'timestamp' => DateCaster::make($value, false),
            'immutable_datetime' => DateCaster::make($value, true),
            'date' => DateCaster::make($value, false, null, true),
            'immutable_date' => DateCaster::make($value, true, null, true),
            default => self::toClass($value, $cast),
        };
    }

    public static function toDb(mixed $value): mixed
    {
        return match (true) {
            $value === null => null,
            $value instanceof DateTimeInterface => $value->format(DateCaster::FORMAT),
            $value instanceof BackedEnum => $value->value,
            is_bool($value) => (int) $value,
            is_array($value), $value instanceof JsonSerializable => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $value instanceof Stringable => (string) $value,
            is_object($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            default => $value,
        };
    }

    private static function toClass(mixed $value, string $class): mixed
    {
        if (!class_exists($class) && !enum_exists($class)) {
            return $value;
        }

        if (is_a($class, BackedEnum::class, true)) {
            return $value instanceof $class ? $value : $class::from($value);
        }

        if (is_a($class, DateTimeInterface::class, true)) {
            return $value instanceof $class
                ? $value
                : DateCaster::make($value, is_a($class, \DateTimeImmutable::class, true) || str_contains($class, 'Immutable'), $class);
        }

        return $value;
    }
}
