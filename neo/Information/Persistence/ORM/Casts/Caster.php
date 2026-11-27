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
    /** @var array<string, int> */
    private static array $kinds = [];

    private const K_CLASS = 0, K_INT = 1, K_FLOAT = 2, K_STRING = 3, K_BOOL = 4, K_ARRAY = 5, K_OBJECT = 6,
        K_DATETIME = 7, K_IMMUTABLE_DATETIME = 8, K_DATE = 9, K_IMMUTABLE_DATE = 10;

    /** Тип приведения определяется один раз на строку-описание, а не strtolower() на каждое значение. */
    private static function kind(string $cast): int
    {
        $name = strtolower($cast);

        if (str_starts_with($name, 'decimal:')) {
            return self::K_FLOAT;
        }

        return match ($name) {
            'int', 'integer' => self::K_INT,
            'float', 'double', 'real' => self::K_FLOAT,
            'string' => self::K_STRING,
            'bool', 'boolean' => self::K_BOOL,
            'array', 'json' => self::K_ARRAY,
            'object' => self::K_OBJECT,
            'datetime', 'timestamp' => self::K_DATETIME,
            'immutable_datetime' => self::K_IMMUTABLE_DATETIME,
            'date' => self::K_DATE,
            'immutable_date' => self::K_IMMUTABLE_DATE,
            default => self::K_CLASS,
        };
    }

    public static function fromDb(mixed $value, ?string $cast): mixed
    {
        if ($value === null || $cast === null) {
            return $value;
        }

        return match (self::$kinds[$cast] ??= self::kind($cast)) {
            self::K_INT => (int) $value,
            self::K_FLOAT => (float) $value,
            self::K_STRING => (string) $value,
            self::K_BOOL => is_string($value) ? !in_array(strtolower($value), ['', '0', 'false'], true) : (bool) $value,
            self::K_ARRAY => is_string($value) ? (array) json_decode($value, true) : (array) $value,
            self::K_OBJECT => is_string($value) ? json_decode($value) : (object) $value,
            self::K_DATETIME => DateCaster::make($value, false),
            self::K_IMMUTABLE_DATETIME => DateCaster::make($value, true),
            self::K_DATE => DateCaster::make($value, false, null, true),
            self::K_IMMUTABLE_DATE => DateCaster::make($value, true, null, true),
            default => self::toClass($value, $cast),
        };
    }

    public static function toDb(mixed $value): mixed
    {
        # Самые частые значения (скаляры и NULL) возвращаем сразу.
        if ($value === null || is_int($value) || is_string($value) || is_float($value)) {
            return $value;
        }

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
