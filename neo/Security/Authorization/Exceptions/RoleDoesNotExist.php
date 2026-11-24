<?php

declare(strict_types=1);

namespace Neo\Security\Authorization\Exceptions;

use InvalidArgumentException;

class RoleDoesNotExist extends InvalidArgumentException
{
    public static function named(string $name, string $guard): self
    {
        return new self("Роль [{$name}] для охранника [{$guard}] не найдена.");
    }

    public static function withId(int|string $id): self
    {
        return new self("Роль с id [{$id}] не найдена.");
    }
}
