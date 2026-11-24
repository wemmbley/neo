<?php

declare(strict_types=1);

namespace Neo\Security\Authentication\Providers;

use Neo\Information\Persistence\ORM\Model;
use Neo\Security\Authentication\Contracts\Authenticatable;
use Neo\Security\Authentication\Contracts\UserProvider;
use Neo\Security\Crypt\CryptManager;

/** Идентификация через ORM-модель (по умолчанию App\Database\Models\User). */
final class ModelUserProvider implements UserProvider
{
    /** @param class-string<Model> $model */
    public function __construct(
        private readonly string $model,
        private readonly CryptManager $crypt,
    ) {}

    public function retrieveById(int|string $id): ?Authenticatable
    {
        $user = ($this->model)::find($id);

        return $user instanceof Authenticatable ? $user : null;
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $query = ($this->model)::query();
        $conditions = 0;

        foreach ($credentials as $column => $value) {
            # Пароль в запросе не участвует: его сравнивает validateCredentials().
            if (str_contains((string) $column, 'password')) {
                continue;
            }

            is_array($value)
                ? $query->whereIn((string) $column, $value)
                : $query->where((string) $column, '=', $value);

            $conditions++;
        }

        # Пустой набор условий вернул бы первого попавшегося пользователя.
        if ($conditions === 0) {
            return null;
        }

        $user = $query->first();

        return $user instanceof Authenticatable ? $user : null;
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        $plain = $credentials['password'] ?? null;

        return is_string($plain) && $plain !== '' && $this->crypt->verify($plain, $user->getAuthPassword());
    }
}
