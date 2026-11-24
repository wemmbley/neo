<?php

declare(strict_types=1);

namespace Neo\Security\Authentication\Contracts;

/** Идентификация: поиск пользователя по идентификатору или учётным данным. */
interface UserProvider
{
    public function retrieveById(int|string $id): ?Authenticatable;

    /** @param array<string, mixed> $credentials например ['email' => ..., 'password' => ...] */
    public function retrieveByCredentials(array $credentials): ?Authenticatable;

    /** @param array<string, mixed> $credentials */
    public function validateCredentials(Authenticatable $user, array $credentials): bool;
}
