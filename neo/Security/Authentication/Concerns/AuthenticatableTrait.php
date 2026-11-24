<?php

declare(strict_types=1);

namespace Neo\Security\Authentication\Concerns;

/**
 * Реализация Authenticatable для ORM-модели.
 *
 *   class User extends Model implements Authenticatable
 *   {
 *       use AuthenticatableTrait;
 *   }
 */
trait AuthenticatableTrait
{
    public function getAuthIdentifier(): int|string|null
    {
        return $this->getKey();
    }

    public function getAuthPassword(): string
    {
        return (string) $this->getAttribute('password');
    }
}
