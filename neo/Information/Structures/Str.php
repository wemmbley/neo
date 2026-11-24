<?php

declare(strict_types=1);

namespace Neo\Information\Structures;

/**
 * Строковые хелперы: имена таблиц, ключей, отношений, URL и сравнение по шаблону.
 *
 * Только статические чистые функции: никакого состояния, безопасно для Swoole.
 */
final class Str
{
    /** @var array<string, string> */
    private static array $snakeCache = [];

    /** @var array<string, string> */
    private static array $camelCache = [];

    /** @var array<string, string> */
    private static array $studlyCache = [];

    public static function snake(string $value): string
    {
        if (isset(self::$snakeCache[$value])) {
            return self::$snakeCache[$value];
        }

        return self::$snakeCache[$value] = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }

    public static function camel(string $value): string
    {
        return self::$camelCache[$value] ??= lcfirst(self::studly($value));
    }

    public static function studly(string $value): string
    {
        return self::$studlyCache[$value] ??= str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $value)));
    }

    public static function kebab(string $value): string
    {
        return str_replace('_', '-', self::snake($value));
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

    public static function contains(string $haystack, string|iterable $needles, bool $ignoreCase = false): bool
    {
        if ($ignoreCase) {
            $haystack = mb_strtolower($haystack);
        }

        foreach ((array) (is_string($needles) ? [$needles] : iterator_to_array($needles, false)) as $needle) {
            $needle = $ignoreCase ? mb_strtolower((string) $needle) : (string) $needle;

            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function startsWith(string $haystack, string|iterable $needles): bool
    {
        foreach (is_string($needles) ? [$needles] : $needles as $needle) {
            if ((string) $needle !== '' && str_starts_with($haystack, (string) $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function endsWith(string $haystack, string|iterable $needles): bool
    {
        foreach (is_string($needles) ? [$needles] : $needles as $needle) {
            if ((string) $needle !== '' && str_ends_with($haystack, (string) $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Добавляет префикс, если его ещё нет. */
    public static function start(string $value, string $prefix): string
    {
        return $prefix . preg_replace('/^(?:' . preg_quote($prefix, '/') . ')+/u', '', $value);
    }

    /** Добавляет суффикс, если его ещё нет. */
    public static function finish(string $value, string $cap): string
    {
        return preg_replace('/(?:' . preg_quote($cap, '/') . ')+$/u', '', $value) . $cap;
    }

    public static function before(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $pos = strpos($subject, $search);

        return $pos === false ? $subject : substr($subject, 0, $pos);
    }

    public static function after(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $pos = strpos($subject, $search);

        return $pos === false ? $subject : substr($subject, $pos + strlen($search));
    }

    public static function beforeLast(string $subject, string $search): string
    {
        $pos = $search === '' ? false : strrpos($subject, $search);

        return $pos === false ? $subject : substr($subject, 0, $pos);
    }

    public static function afterLast(string $subject, string $search): string
    {
        $pos = $search === '' ? false : strrpos($subject, $search);

        return $pos === false ? $subject : substr($subject, $pos + strlen($search));
    }

    /**
     * Сравнение со звёздочкой: `Str::is('admin/*', $path)`.
     *
     * @param string|iterable<string> $pattern
     */
    public static function is(string|iterable $pattern, string $value, bool $ignoreCase = false): bool
    {
        foreach (is_string($pattern) ? [$pattern] : $pattern as $item) {
            $item = (string) $item;

            if ($item === $value) {
                return true;
            }

            $regex = '#^' . str_replace('\*', '.*', preg_quote($item, '#')) . '\z#su' . ($ignoreCase ? 'i' : '');

            if (preg_match($regex, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function limit(string $value, int $limit = 100, string $end = '...'): string
    {
        return mb_strlen($value) <= $limit ? $value : rtrim(mb_substr($value, 0, $limit)) . $end;
    }

    public static function slug(string $value, string $separator = '-'): string
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', $separator, $value);

        return trim($value, $separator);
    }

    /** Криптостойкая случайная строка из [A-Za-z0-9]. */
    public static function random(int $length = 16): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, 61)];
        }

        return $result;
    }

    /** Сбрасывает кэши. Нужен тестам и долгоживущим воркерам. */
    public static function flushCache(): void
    {
        self::$snakeCache = self::$camelCache = self::$studlyCache = [];
    }
}
