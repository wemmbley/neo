<?php

declare(strict_types=1);

namespace App\Neo\Helpers\Primitives;

class Arr
{
    /**
     * Get elements count in array.
     *
     * <code>
     *      Arr::count([1,2,3]); // 3
     * </code>
     *
     * @param array $arr
     * @return int
     */
    public static function count(array $arr): int
    {
        return count($arr);
    }

    /**
     * Get last array element.
     *
     * <code>
     *      Arr::last(['one', 'two', 'three']); // 'three'
     * </code>
     *
     * @param array $array
     * @return mixed
     */
    public static function last(array $array): mixed
    {
        return end($array);
    }

    /**
     * @param array $array
     * @return int|string|null
     */
    public static function lastKey(array $array): int|string|null
    {
        return array_key_last($array);
    }

    /**
     * Check, if array has required keys.
     *
     * <code>
     *      Arr::hasKeys(['one'], ['hey', 'wow', 'nope']); // false
     *      Arr::hasKeys(['one'], ['hey', 'one', 'nope']); // true
     * </code>
     *
     * @param $requiredKeys
     * @param $array
     * @return bool
     */
    public static function hasKeys($requiredKeys, $array): bool
    {
        return count(array_intersect_key($requiredKeys, array_keys($array))) === count($requiredKeys);
    }

    /**
     * Delete empty array elements.
     *
     * @param array $array
     * @return array
     */
    public static function filter(array $array): array
    {
        return array_filter($array);
    }

    /**
     * Convert array to string.
     *
     * @param string $separator
     * @param array $array
     * @return string
     */
    public static function toString(string $separator, array $array): string
    {
        return implode($separator, $array);
    }

    /**
     * Reindex array.
     *
     * <code>
     *      // from
     *      [2 => 'hi', 9 => 'Rustam']
     *
     *      // to
     *      [0 => 'hi', 1 => 'Rustam']
     * </code>
     *
     * @param array $array
     * @return array
     */
    public static function reindex(array $array): array
    {
        return array_values($array);
    }

    /**
     * Get array keys.
     *
     * @param array $array
     * @return array
     */
    public static function keys(array $array): array
    {
        return array_keys($array);
    }

    /**
     * Combine two array to one by key-value principle.
     *
     * @param array $keysArray
     * @param array $valuesArray
     * @return array
     */
    public static function combine(array $keysArray, array $valuesArray): array
    {
        return array_combine($keysArray, $valuesArray);
    }

    /**
     * Check, if array has key
     *
     * @param array $array
     * @param string $key
     * @return bool
     */
    public static function has(array $array, string $key): bool
    {
        foreach ($array as $value)
            if ($value === $key) return true;
                return false;
    }

    /**
     * Get random array element.
     *
     * @param array $array
     * @param int $count
     * @return string|array|int
     */
    public static function random(array $array, int $count = 1): string|array|int
    {
        $randomIndex = array_rand($array, $count);

        return $array[$randomIndex];
    }

    /**
     * Compare two arrays.
     *
     * @param array $array
     * @param array $array2
     * @return array
     */
    public static function compare(array $array, array $array2): array
    {
        return array_diff($array, $array2);
    }

    /**
     * Map array.
     *
     * @param \Closure $closure
     * @param ...$arrays
     * @return mixed
     */
    public static function map(\Closure $closure, ...$arrays): mixed
    {
        return array_map($closure, ...$arrays);
    }
}