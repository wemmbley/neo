<?php

declare(strict_types=1);

namespace Neo\Information\Structures;

use ArrayAccess;

/**
 * Хелперы для массивов с «точечной» нотацией (`Arr::get($a, 'user.name')`).
 *
 * Только статические чистые функции: никакого состояния, безопасно для Swoole.
 */
final class Arr
{
    public static function accessible(mixed $value): bool
    {
        return is_array($value) || $value instanceof ArrayAccess;
    }

    public static function wrap(mixed $value): array
    {
        return match (true) {
            $value === null => [],
            is_array($value) => $value,
            default => [$value],
        };
    }

    public static function exists(array|ArrayAccess $array, string|int $key): bool
    {
        return $array instanceof ArrayAccess ? $array->offsetExists($key) : array_key_exists($key, $array);
    }

    public static function get(array|ArrayAccess $array, string|int|null $key, mixed $default = null): mixed
    {
        if ($key === null) {
            return $array;
        }

        if (self::exists($array, $key)) {
            return $array[$key];
        }

        if (!is_string($key) || !str_contains($key, '.')) {
            return $default instanceof \Closure ? $default() : $default;
        }

        foreach (explode('.', $key) as $segment) {
            if (self::accessible($array) && self::exists($array, $segment)) {
                $array = $array[$segment];
            } else {
                return $default instanceof \Closure ? $default() : $default;
            }
        }

        return $array;
    }

    public static function has(array|ArrayAccess $array, string|int|array $keys): bool
    {
        $keys = (array) $keys;

        if ($keys === []) {
            return false;
        }

        foreach ($keys as $key) {
            $sub = $array;

            if (self::exists($sub, $key)) {
                continue;
            }

            foreach (explode('.', (string) $key) as $segment) {
                if (self::accessible($sub) && self::exists($sub, $segment)) {
                    $sub = $sub[$segment];
                } else {
                    return false;
                }
            }
        }

        return true;
    }

    public static function hasAny(array|ArrayAccess $array, string|array $keys): bool
    {
        foreach ((array) $keys as $key) {
            if (self::has($array, $key)) {
                return true;
            }
        }

        return false;
    }

    public static function set(array &$array, string|int|null $key, mixed $value): array
    {
        if ($key === null) {
            return $array = $value;
        }

        $segments = explode('.', (string) $key);

        foreach ($segments as $i => $segment) {
            if (count($segments) === 1) {
                break;
            }

            unset($segments[$i]);

            if (!isset($array[$segment]) || !is_array($array[$segment])) {
                $array[$segment] = [];
            }

            $array = &$array[$segment];
        }

        $array[array_shift($segments)] = $value;

        return $array;
    }

    public static function forget(array &$array, string|int|array $keys): void
    {
        $original = &$array;

        foreach ((array) $keys as $key) {
            if (array_key_exists($key, $array)) {
                unset($array[$key]);
                continue;
            }

            $parts = explode('.', (string) $key);
            $array = &$original;

            while (count($parts) > 1) {
                $part = array_shift($parts);

                if (isset($array[$part]) && is_array($array[$part])) {
                    $array = &$array[$part];
                } else {
                    continue 2;
                }
            }

            unset($array[array_shift($parts)]);
        }
    }

    public static function pull(array &$array, string|int $key, mixed $default = null): mixed
    {
        $value = self::get($array, $key, $default);
        self::forget($array, $key);

        return $value;
    }

    public static function only(array $array, string|int|array $keys): array
    {
        return array_intersect_key($array, array_flip((array) $keys));
    }

    public static function except(array $array, string|int|array $keys): array
    {
        self::forget($array, $keys);

        return $array;
    }

    public static function first(iterable $array, ?callable $callback = null, mixed $default = null): mixed
    {
        foreach ($array as $key => $value) {
            if ($callback === null || $callback($value, $key)) {
                return $value;
            }
        }

        return $default instanceof \Closure ? $default() : $default;
    }

    public static function last(array $array, ?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return $array === [] ? ($default instanceof \Closure ? $default() : $default) : end($array);
        }

        return self::first(array_reverse($array, true), $callback, $default);
    }

    public static function isAssoc(array $array): bool
    {
        return !array_is_list($array);
    }

    public static function isList(array $array): bool
    {
        return array_is_list($array);
    }

    /** Плоский массив с ключами `a.b.c`. */
    public static function dot(iterable $array, string $prepend = ''): array
    {
        $results = [];

        foreach ($array as $key => $value) {
            if (is_array($value) && $value !== []) {
                $results = array_merge($results, self::dot($value, $prepend . $key . '.'));
            } else {
                $results[$prepend . $key] = $value;
            }
        }

        return $results;
    }

    public static function undot(iterable $array): array
    {
        $results = [];

        foreach ($array as $key => $value) {
            self::set($results, (string) $key, $value);
        }

        return $results;
    }

    public static function flatten(iterable $array, float|int $depth = INF): array
    {
        $result = [];

        foreach ($array as $item) {
            if (!is_array($item)) {
                $result[] = $item;
            } elseif ($depth === 1) {
                $result = array_merge($result, array_values($item));
            } else {
                $result = array_merge($result, self::flatten($item, $depth - 1));
            }
        }

        return $result;
    }

    public static function pluck(iterable $array, string|int $value, string|int|null $key = null): array
    {
        $results = [];

        foreach ($array as $item) {
            $itemValue = is_array($item) || $item instanceof ArrayAccess ? self::get($item, $value) : ($item->{$value} ?? null);

            if ($key === null) {
                $results[] = $itemValue;
            } else {
                $itemKey = is_array($item) || $item instanceof ArrayAccess ? self::get($item, $key) : ($item->{$key} ?? null);
                $results[is_scalar($itemKey) ? $itemKey : (string) $itemKey] = $itemValue;
            }
        }

        return $results;
    }

    public static function where(array $array, callable $callback): array
    {
        return array_filter($array, $callback, ARRAY_FILTER_USE_BOTH);
    }

    public static function map(array $array, callable $callback): array
    {
        $keys = array_keys($array);

        return array_combine($keys, array_map($callback, $array, $keys));
    }

    public static function add(array $array, string|int $key, mixed $value): array
    {
        if (self::get($array, $key) === null) {
            self::set($array, $key, $value);
        }

        return $array;
    }

    /** Рекурсивное слияние, где скаляры правого массива заменяют левые (в отличие от array_merge_recursive). */
    public static function mergeRecursiveDistinct(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            $left[$key] = is_array($value) && isset($left[$key]) && is_array($left[$key]) && !array_is_list($value)
                ? self::mergeRecursiveDistinct($left[$key], $value)
                : $value;
        }

        return $left;
    }

    /** Рекурсивно применяет $callback к значениям-строкам. */
    public static function mapStringsRecursive(array $array, callable $callback): array
    {
        foreach ($array as $key => $value) {
            $array[$key] = match (true) {
                is_array($value) => self::mapStringsRecursive($value, $callback),
                is_string($value) => $callback($value, $key),
                default => $value,
            };
        }

        return $array;
    }
}
