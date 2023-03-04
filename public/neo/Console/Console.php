<?php

declare(strict_types=1);

namespace App\Neo\Console;

use App\Neo\Helpers\Primitives\Arr;
use App\Neo\Helpers\Primitives\Str;

class Console
{
    private static array $params = [];
    private static \Closure $callback;

    private static array $codes = [
        'bold' => 1, 'italic' => 3, 'underline'=> 4, 'strikethrough' => 9,
        'black' => 30, 'red' => 31, 'green' => 32, 'yellow' => 33,
        'blue' => 34, 'magenta' => 35, 'cyan' => 36, 'white' => 37,
        'blackbg' => 40, 'redbg' => 41, 'greenbg' => 42, 'yellowbg' => 44,
        'bluebg' => 44, 'magentabg' => 45, 'cyanbg' => 46, 'lightgreybg' => 47
    ];

    public static function print(array $format = [], string $text = ''): void
    {
        $formatMap = Arr::map(function ($i) {
            return static::$codes[$i];
            }, $format);

        echo "\e[" . Arr::toString(';', $formatMap) . 'm' . $text . "\e[0m";
    }

    public static function printLine(array $format = [], string $text = ''): void
    {
        static::print($format, $text);

        echo "\r\n";
    }

    public static function success(string $text)
    {
        static::print(['green', 'bold'], '[Success] ');
        static::printLine([], $text);
    }

    public static function isParams(string $params, \Closure $closure): static
    {
        static::$params = Str::split($params, ' ');
        static::$callback = $closure;

        return new static;
    }

    public static function withInput(array $argv): void
    {
        unset($argv[0]);

        if (count(Arr::compare(static::$params, $argv)) === 0) {
            $callback = static::$callback;
            $callback();
        }
    }

    public static function isConsole(): bool
    {
        return php_sapi_name() === 'cli';
    }
}