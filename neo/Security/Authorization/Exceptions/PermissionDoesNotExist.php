<?php

declare(strict_types=1);

namespace Neo\Security\Authorization\Exceptions;

use InvalidArgumentException;

class PermissionDoesNotExist extends InvalidArgumentException
{
    public static function named(string $name, string $guard): self
    {
        return new self("Право [{$name}] для охранника [{$guard}] не найдено.");
    }

    public static function withId(int|string $id): self
    {
        return new self("Право с id [{$id}] не найдено.");
    }
}
