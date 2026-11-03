<?php

declare(strict_types=1);

namespace Neo\Support;

/**
 * Помощники для массивов с «точечной» записью ключей:
 * `Arr::get($a, 'user.name')`.
 */
final class Arr
{
    /**
     * Достаёт значение по ключу или пути через точку.
     *
     * Сначала проверяется ключ целиком (на случай, когда точка входит в сам ключ).
     */
    public static function get(array $array, string|int|null $key, mixed $default = null): mixed
    {
        if ($key === null) {
            return $array;
        }

        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        if (!is_string($key) || !str_contains($key, '.')) {
            return $default;
        }

        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }

            $array = $array[$segment];
        }

        return $array;
    }

    /**
     * Есть ли значение по пути (даже если оно равно null).
     */
    public static function has(array $array, string|int $key): bool
    {
        $missing = new \stdClass();

        return self::get($array, $key, $missing) !== $missing;
    }

    /**
     * Записывает значение по пути через точку,
     * создавая промежуточные массивы.
     */
    public static function set(array &$array, string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $last = count($segments) - 1;

        foreach ($segments as $i => $segment) {
            if ($i === $last) {
                $array[$segment] = $value;

                return;
            }

            if (!isset($array[$segment]) || !is_array($array[$segment])) {
                $array[$segment] = [];
            }

            $array = &$array[$segment];
        }
    }

    /**
     * Сливает настройки: ассоциативные массивы сливаются
     * вглубь, а списки (`[a, b]`) и скаляры заменяются целиком.
     * Так `paths => ['/my']` не смешивается с дефолтными путями.
     * Пустой массив поверх ассоциативного оставляет его как есть.
     *
     * @param array<string, mixed> $base     Значения по умолчанию.
     * @param array<string, mixed> $override Что переопределяем.
     *
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            // Пустой массив поверх ассоциативных настроек их не стирает: `'aliases' => []` значит «ничего не добавляю».
            if (
                is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && !array_is_list($base[$key])
                && ($value === [] || !array_is_list($value))
            ) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Раскрывает путь со звёздочкой в конкретные пути:
     * `items.*.name` для двух элементов даёт `items.0.name`,
     * `items.1.name`.
     *
     * @return list<string>
     */
    public static function expandWildcard(array $data, string $pattern): array
    {
        $paths = [''];

        foreach (explode('.', $pattern) as $segment) {
            $next = [];

            foreach ($paths as $path) {
                if ($segment !== '*') {
                    $next[] = $path === '' ? $segment : $path . '.' . $segment;
                    continue;
                }

                $value = $path === '' ? $data : self::get($data, $path);

                if (!is_array($value)) {
                    continue;
                }

                foreach (array_keys($value) as $key) {
                    $next[] = $path === '' ? (string) $key : $path . '.' . $key;
                }
            }

            $paths = $next;
        }

        return $paths;
    }
}
