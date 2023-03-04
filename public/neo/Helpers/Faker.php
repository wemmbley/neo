<?php

declare(strict_types=1);

namespace App\Neo\Helpers;

use App\Neo\Helpers\Primitives\Arr;
use App\Neo\Helpers\Primitives\Str;

class Faker
{
    protected static array $names = [
        'Mike', 'John', 'Jeremy', 'Susan', 'Brad',
        'Leonardo', 'Walter', 'Derek', 'Michael'
    ];

    public static function password(): string
    {
        return password_hash(static::randomText(12), PASSWORD_BCRYPT);
    }

    public static function name(): string
    {
        return Arr::random(static::$names);
    }

    public static function randomText(int $size): string
    {
        return Str::rand($size);
    }
}