<?php

declare(strict_types=1);

namespace App\Neo\MimeTypes;

class Mime
{
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

        $mimes = require_once Path::abs($mimePath);

        if ( ! isset($mimes[$extension]))
            return '';

        return static::getMime($mimes, $extension);
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