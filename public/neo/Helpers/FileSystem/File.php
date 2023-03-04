<?php

declare(strict_types=1);

namespace App\Neo\Helpers\FileSystem;

class File
{
    /**
     * Get content from file
     *
     * <code>
     *      File::get('index.html');
     * </code>
     *
     * @param string $stream
     * @return bool|string
     */
    public static function get(string $stream): bool|string
    {
        return file_get_contents($stream);
    }

    /**
     * Put content into file
     *
     * <code>
     *      File::put('users.txt', 'my text here');
     * </code>
     *
     * @param string $filename
     * @param string $stream
     * @return void
     */
    public static function put(string $filename, string $stream): void
    {
        file_put_contents($filename, $stream);
    }

    /**
     * Check, if file exists
     *
     * <code>
     *      File::exists('catalog/file.txt');
     * </code>
     *
     * @param string $path
     * @return bool
     */
    public static function exists(string $path): bool
    {
        return file_exists($path);
    }

    /**
     * Get file extension.
     *
     * @param string $path
     * @return string
     */
    public static function extension(string $path): string
    {
        return Path::info($path)['extension'];
    }
}