<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Support;

/**
 * Минимальные строковые хелперы для имён таблиц, ключей и отношений.
 */
final class Str
{
    public static function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }

    public static function camel(string $value): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $value))));
    }

    public static function basename(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    public static function plural(string $word): string
    {
        return match (true) {
            (bool) preg_match('/(s|x|z|ch|sh)$/i', $word) => $word . 'es',
            (bool) preg_match('/[^aeiou]y$/i', $word) => substr($word, 0, -1) . 'ies',
            default => $word . 's',
        };
    }

    public static function singular(string $word): string
    {
        return match (true) {
            (bool) preg_match('/[^aeiou]ies$/i', $word) => substr($word, 0, -3) . 'y',
            (bool) preg_match('/(s|x|z|ch|sh)es$/i', $word) => substr($word, 0, -2),
            str_ends_with($word, 's') && !str_ends_with($word, 'ss') => substr($word, 0, -1),
            default => $word,
        };
    }
}
