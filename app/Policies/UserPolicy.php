<?php

declare(strict_types=1);

namespace App\Policies;

use App\Database\Models\User;
use Neo\Security\Authorization\Gate\AuthorizationResponse;
use Neo\Security\Authorization\Policy\Policy;

/**
 * Пример политики. Находится автоматически: App\Database\Models\User => App\Policies\UserPolicy.
 *
 *   Gate::allows('update', $user);        // $user это редактируемый
 *   Gate::allows('viewAny', User::class);
 */
final class UserPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('user.read');
    }

    public function view(User $user, User $target): bool
    {
        return $user->id === $target->id || $user->hasPermissionTo('user.read');
    }

    public function update(User $user, User $target): bool|AuthorizationResponse
    {
        return $user->id === $target->id || $user->hasPermissionTo('user.update')
            ? true
            : $this->deny('Нельзя редактировать чужой профиль.');
    }

    public function delete(User $user, User $target): bool|AuthorizationResponse
    {
        if ($user->id === $target->id) {
            return $this->deny('Нельзя удалить самого себя.');
        }

        return $user->hasPermissionTo('user.delete');
    }
}
