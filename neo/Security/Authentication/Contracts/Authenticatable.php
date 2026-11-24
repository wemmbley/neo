<?php

declare(strict_types=1);

namespace Neo\Security\Authentication\Contracts;

/**
 * Сущность, которая может войти в систему (обычно модель User).
 * Готовая реализация для ORM-модели: {@see \Neo\Security\Authentication\Concerns\AuthenticatableTrait}.
 */
interface Authenticatable
{
    /** Значение первичного ключа: именно оно хранится в сессии. */
    public function getAuthIdentifier(): int|string|null;

    /** Хеш пароля (результат Crypt::hash()). */
    public function getAuthPassword(): string;
}
