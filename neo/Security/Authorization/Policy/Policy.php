<?php

declare(strict_types=1);

namespace Neo\Security\Authorization\Policy;

use Neo\Security\Authorization\Gate\AuthorizationResponse;

/**
 * Необязательный базовый класс политик: только короткие конструкторы ответов.
 * Политика может быть и обычным классом без предка.
 *
 * Политика отвечает за ОДНУ модель. Каждый публичный метод это способность: первым
 * аргументом приходит пользователь, вторым модель (если проверка шла на экземпляре):
 *
 *   final class PostPolicy extends Policy
 *   {
 *       public function before(User $user, string $ability): ?bool   // необязательно: до всех методов
 *       {
 *           return $user->hasRole('admin') ? true : null;            // null: решает сам метод
 *       }
 *
 *       public function update(User $user, Post $post): bool|AuthorizationResponse
 *       {
 *           return $user->id === $post->userId ? true : $this->deny('Это не ваша запись.');
 *       }
 *
 *       public function create(User $user): bool { return $user->hasPermissionTo('post.create'); }
 *   }
 *
 * Гости: метод вызывается для неавторизованного только если тип первого аргумента допускает null (`?User $user`).
 */
abstract class Policy
{
    protected function allow(string $message = ''): AuthorizationResponse
    {
        return AuthorizationResponse::allow($message);
    }

    protected function deny(string $message = ''): AuthorizationResponse
    {
        return AuthorizationResponse::deny($message);
    }

    protected function denyAsNotFound(string $message = ''): AuthorizationResponse
    {
        return AuthorizationResponse::denyAsNotFound($message);
    }

    protected function denyWithStatus(int $status, string $message = ''): AuthorizationResponse
    {
        return AuthorizationResponse::denyWithStatus($status, $message);
    }
}
