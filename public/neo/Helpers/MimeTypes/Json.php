<?php

declare(strict_types=1);

namespace App\Neo\Helpers\MimeTypes;

class Json
{
    /**
     * Convert string to JSON.
     *
     * @param mixed $raw
     * @return string
     */
    public static function encode(mixed $raw): string
    {
        return json_encode($raw);
    }

    /**
     * @param string $json
     * @return array
     */
    public static function decode(string $json): array
    {
        return json_decode($json, true);
    }
}