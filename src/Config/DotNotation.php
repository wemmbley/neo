<?php

declare(strict_types=1);

namespace Neo\Config;

/**
 * Работа с вложенными массивами через dot-нотацию:
 * "auth.errors.failed".
 *
 * Класс ничего не знает о языках и годится для любых
 * массивов (конфиги и т.п.).
 */
final class DotNotation
{
    /**
     * Есть ли значение по ключу (даже если оно null).
     */
    public static function has(array $array, string $key): bool
    {
        $marker = new \stdClass();

        return self::get($array, $key, $marker) !== $marker;
    }

    /**
     * Достаёт значение по ключу "a.b.c".
     * Пустой ключ возвращает весь массив.
     */
    public static function get(array $array, string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $array;
        }

        // Ключ может быть "плоским" и содержать точку буквально.
        if (array_key_exists($key, $array)) {
            return $array[$key];
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
     * Записывает значение по ключу "a.b.c", создавая
     * недостающие уровни.
     */
    public static function set(array &$array, string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $last = array_pop($segments);

        $node = &$array;
        foreach ($segments as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }

            $node = &$node[$segment];
        }

        $node[$last] = $value;
    }

    /**
     * Разворачивает вложенный массив в плоский:
     * ['a' => ['b' => 1]] → ['a.b' => 1].
     *
     * @return array<string, mixed>
     */
    public static function flatten(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value) && $value !== []) {
                $result += self::flatten($value, $path);
            } else {
                $result[$path] = $value;
            }
        }

        return $result;
    }

    /**
     * Раскрывает шаблон со звёздочкой в реальные пути:
     * 'tags.*' → ['tags.0', 'tags.1'],
     * 'items.*.name' → ['items.0.name', 'items.1.name'].
     *
     * Если элементов нет, возвращает пустой список.
     * Путь после звёздочки не проверяется на существование:
     * так `required` увидит отсутствующее поле.
     *
     * @return list<string>
     */
    public static function expandWildcard(array $array, string $pattern): array
    {
        $paths = [''];

        foreach (explode('.', $pattern) as $segment) {
            $next = [];

            foreach ($paths as $path) {
                if ($segment !== '*') {
                    $next[] = $path === '' ? $segment : $path . '.' . $segment;
                    continue;
                }

                // get() с пустым ключом возвращает весь массив: корень обрабатывается так же, как вложенный узел.
                $node = self::get($array, $path);

                if (!is_array($node)) {
                    continue;
                }

                foreach (array_keys($node) as $key) {
                    $next[] = $path === '' ? (string) $key : $path . '.' . $key;
                }
            }

            $paths = $next;
        }

        return $paths;
    }
}