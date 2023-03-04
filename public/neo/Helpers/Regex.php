<?php

declare(strict_types=1);

namespace App\Neo\Helpers;

use App\Neo\Helpers\Primitives\Arr;
use App\Neo\Helpers\Primitives\Str;
use Exception;

/**
 * Class for easy regular expressions use
 *
 * Code examples:
 *
 * <code>
 *
 *      Regex::match('/[0-9]/')
 *             ->string('hello 22');
 *      // Output: 2
 *
 *
 *      Regex::matchAll('/[0-9]+/')
 *             ->string('hello 1 and 23 and 88 so 99');
 *      // Output: [1,23,88,99]
 *
 *
 *      Regex::replace('/[0-9]+/')
 *             ->string('hello 22 and 33')
 *             ->to('99');
 *      // Output: 'hello 99 and 33'
 *
 *      Regex::replaceAll('/[0-9]+/')
 *             ->string('hello 22 and 33')
 *             ->to('99');
 *      // Output: 'hello 99 and 99'
 *
 * </code>
 */
class Regex
{
    private static string $pattern;
    private static string $replacement;
    private static string $method;

    public static function match(string $pattern): static
    {
        static::$pattern = $pattern;
        static::$method = __METHOD__;

        return new static;
    }

    public static function matchAll(string $pattern): static
    {
        static::$pattern = $pattern;
        static::$method = __METHOD__;

        return new static;
    }

    public static function replace(string $pattern): static
    {
        static::$pattern = $pattern;
        static::$method = __METHOD__;

        return new static;
    }

    public static function replaceAll(string $pattern): static
    {
        static::$pattern = $pattern;
        static::$method = __METHOD__;

        return new static;
    }

    public static function string(string $string)
    {
        $regexMethod = Arr::last(Str::split(static::$method, '\\'));

        switch ($regexMethod) {
            case 'Regex::replace':
            case 'Regex::replaceAll': {
                static::$replacement = $string;

                return new static;
            }
            case 'Regex::match': {
                preg_match(static::$pattern, $string, $matches);

                return $matches;
            }
            case 'Regex::matchAll': {
                preg_match_all(static::$pattern, $string, $matches);

                return $matches;
            }
            default: {
                throw new Exception('regex type not found ' . static::$method);
            }
        }
    }

    public static function to(array|string $replacement): array|string
    {
        (static::$method === 'Regex::replaceAll') ? $limit = 1 : $limit = -1;

        $replaced = preg_replace(static::$pattern, $replacement, static::$replacement, $limit);

        if (is_null($replaced)) { return [[],[]]; } return $replaced;
    }
}