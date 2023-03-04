<?php

declare(strict_types=1);

namespace App\Neo\Helpers\FileSystem;

use App\Neo\Console\Console;
use App\Neo\Helpers\Primitives\Arr;

class Path
{
    /**
     * Get root app path.
     *
     * @param string $relative
     * @return string
     */
    public static function abs(string $relative): string
    {
        if (Console::isConsole())
            return '.' . dirname(getcwd()) . $relative;

        return sprintf('../%s', $relative);
    }

    /**
     * Get path info.
     *
     * @param string $path
     * @return array
     */
    public static function info(string $path): array
    {
        return pathinfo($path);
    }

    /**
     * Scan path.
     *
     * @param string $path
     * @return array
     */
    public static function scan(string $path): array
    {
        $directory = scandir($path);

        unset($directory[0]);
        unset($directory[1]);

        return Arr::reindex($directory);
    }
}