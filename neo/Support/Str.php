<?php

declare(strict_types=1);

namespace Neo\Support;

/**
 * Простые строковые помощники для имён таблиц и классов
 * (английские слова).
 */
final class Str
{
    /**
     * `OrderItem` -> `order_item`, `HTTPRequest` -> `http_request`.
     */
    public static function snake(string $value): string
    {
        $value = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $value);

        return strtolower($value);
    }

    /**
     * Множественное число по простым правилам:
     * `category` -> `categories`, `box` -> `boxes`,
     * `order` -> `orders`.
     *
     * Неправильные слова (`person`, `child`) задавайте через
     * `#[Table('people')]` явно.
     */
    public static function plural(string $word): string
    {
        return match (true) {
            preg_match('/[^aeiou]y$/i', $word) === 1 => substr($word, 0, -1) . 'ies',
            preg_match('/(s|x|z|ch|sh)$/i', $word) === 1 => $word . 'es',
            default => $word . 's',
        };
    }
}
