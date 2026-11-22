<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Casts;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Единственное место, которое знает про Chronos. Поправьте две константы под реальные пути.
 * Если класса нет, используется нативный DateTime / DateTimeImmutable.
 */
final class DateCaster
{
    public const MUTABLE = 'Neo\\Support\\Chronos';
    public const IMMUTABLE = 'Neo\\Support\\ChronosImmutable';

    public const FORMAT = 'Y-m-d H:i:s';

    /** @param class-string<DateTimeInterface>|null $class */
    public static function make(mixed $value, bool $immutable, ?string $class = null, bool $dateOnly = false): DateTimeInterface
    {
        if ($class === null || !is_a($class, DateTimeInterface::class, true) || (new \ReflectionClass($class))->isInterface()) {
            $class = self::resolve($immutable ? self::IMMUTABLE : self::MUTABLE, $immutable);
        }

        if ($value instanceof DateTimeInterface) {
            $value = $value->format(self::FORMAT);
        }

        $string = is_int($value) || (is_string($value) && ctype_digit($value)) ? '@' . $value : (string) $value;

        $date = method_exists($class, 'parse') ? $class::parse($string) : new $class($string);

        return $dateOnly ? $date->setTime(0, 0) : $date;
    }

    private static function resolve(string $preferred, bool $immutable): string
    {
        return class_exists($preferred) ? $preferred : ($immutable ? DateTimeImmutable::class : DateTime::class);
    }
}
