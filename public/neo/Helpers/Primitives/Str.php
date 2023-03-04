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
}