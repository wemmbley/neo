<?php

declare(strict_types=1);

namespace Neo\Security\Authorization\Gate;

/**
 * `$user->can('update', $post)` для модели пользователя (Authenticatable).
 * Проверка идёт через Gate от имени этого пользователя, а не текущего.
 */
trait Authorizable
{
    /** Разрешены ВСЕ способности. @param iterable<string>|string $abilities */
    public function can(iterable|string $abilities, mixed $arguments = []): bool
    {
        return kernel(GateManager::class)->forUser($this)->check($abilities, $arguments);
    }

    /** Разрешена ХОТЯ БЫ одна способность. @param iterable<string>|string $abilities */
    public function canAny(iterable|string $abilities, mixed $arguments = []): bool
    {
        return kernel(GateManager::class)->forUser($this)->any($abilities, $arguments);
    }

    /** @param iterable<string>|string $abilities */
    public function cannot(iterable|string $abilities, mixed $arguments = []): bool
    {
        return !$this->can($abilities, $arguments);
    }

    /** @param iterable<string>|string $abilities */
    public function cant(iterable|string $abilities, mixed $arguments = []): bool
    {
        return $this->cannot($abilities, $arguments);
    }
}
