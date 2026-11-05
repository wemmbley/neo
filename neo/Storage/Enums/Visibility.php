<?php

declare(strict_types=1);

namespace Neo\Storage\Enums;

use Neo\Storage\Exceptions\UnsupportedOperationException;

enum Visibility: string
{
    case Public = 'public';
    case Private = 'private';

    /**
     * Приводит "что угодно" (enum, строку, null) к Visibility.
     *
     * Неизвестная строка - это ошибка программиста, а не пользователя,
     * поэтому падаем сразу, а не молча берём значение по умолчанию.
     *
     * @throws UnsupportedOperationException
     */
    public static function resolve(self|string|null $value, self $default): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value === null) {
            return $default;
        }

        return self::tryFrom($value)
            ?? throw new UnsupportedOperationException("Unknown visibility [{$value}].");
    }
}