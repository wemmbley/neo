<?php

declare(strict_types=1);

namespace App\Neo\Helpers\Primitives;

class Str
{
    /**
     * Make string lowercase.
     *
     * @param string $str
     * @return string
     */
    public static function lower(string $str): string
    {
        return strtolower($str);
    }

    public static function upper(string $str): string
    {
        return strtoupper($str);
    }

    /**
     * Convert string to array.
     *
     * @param string $separator
     * @param string $string
     * @return array
     */
    public static function toArray(string $separator, string $string): array
    {
        return explode($separator, $string);
    }

    /**
     * Split string by separator.
     *
     * @param string $str
     * @param string $separator
     * @return array
     */
    public static function split(string $str, string $separator): array
    {
        return explode($separator, $str);
    }

    /**
     * Generate random text.
     *
     * @param int $size
     * @return string
     * @throws \Exception
     */
    public static function rand(int $size): string
    {
        return bin2hex(random_bytes($size));
    }

    /**
     * Check, if string contains substring.
     *
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    public static function contains(string $haystack, string $needle): bool
    {
        return str_contains($haystack, $needle);
    }

    /**
     * Remove characters from start of string.
     *
     * <code>
     *  Str::removeChars('hello!', 4); // output: 'o!'
     * </code>
     *
     * @param string $string
     * @param int $count
     * @return string
     */
    public static function removeChars(string $string, int $count): string
    {
        $strArray = str_split($string);

        for ($i = 0; $i < $count; $i++) {
            unset($strArray[$i]);
        }

        return Arr::toString('', $strArray);
    }

    public static function replace(string $from, string $to, string $subject, int $count = -1): array|string
    {
        return str_replace($from, $to, $subject, $count);
    }
}