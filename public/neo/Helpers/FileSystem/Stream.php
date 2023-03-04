<?php

declare(strict_types=1);

namespace App\Neo\Helpers\FileSystem;

class Stream
{
    public static function input(): bool|string
    {
        return file_get_contents('php://input');
    }
}