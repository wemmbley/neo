<?php

declare(strict_types=1);

namespace App\Neo\Helpers\MimeTypes;

use App\Neo\Helpers\FileSystem\File;
use App\Neo\Helpers\FileSystem\Path;
use Exception;

class Mime
{
    private static array $mimes;

    /**
     * Get file MIME. It takes from user config in mimes.php.
     *
     * @param string $extension
     * @return string
     * @throws Exception
     */
    public static function extension(string $extension): string
    {
        $mimePath = '/app/Config/mimes.php';

        static::validateFile($mimePath);

        if ( ! isset(static::$mimes))
            static::$mimes = require_once Path::abs($mimePath);

        if ( ! array_key_exists($extension, static::$mimes))
            return '';

        return static::getMime(static::$mimes, $extension);
    }

    /**
     * Check if file exists in folder or throw exception.
     *
     * @throws Exception
     */
    protected static function validateFile(string $mimePath): void
    {
        if ( ! File::exists(Path::abs($mimePath))) {
            throw new Exception(
                sprintf('Mime config not found in path %s',
                    Path::abs($mimePath)
                )
            );
        }
    }

    /**
     * Get first mime from array if it arrays
     *
     * @param array $mimes
     * @param string $extension
     * @return string
     */
    protected static function getMime(array $mimes, string $extension): string
    {
        if (is_array($mimes[$extension]) && isset($mimes[$extension][0]))
            return $mimes[$extension][0];

        return $mimes[$extension];
    }

    /**
     * Get MIME from file.
     *
     * @param string $path
     * @return string
     * @throws Exception
     */
    public static function file(string $path): string
    {
        $mime = static::extension(File::extension($path));

        if ( ! empty($mime))
            return $mime;

        return mime_content_type($path);
    }
}