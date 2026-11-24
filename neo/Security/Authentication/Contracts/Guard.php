<?php

declare(strict_types=1);

namespace Neo\Security\Authentication\Contracts;

/**
 * Способ ответить на вопрос «кто сейчас делает запрос». Охранник не хранит
 * пользователя в своих свойствах: он живёт в одном экземпляре на процесс (Swoole),
 * текущий пользователь лежит в {@see \Neo\Security\Authentication\AuthContext}.
 */
interface Guard
{
    public function check(): bool;

    public function guest(): bool;

    public function user(): ?Authenticatable;

    public function id(): int|string|null;

    /** Проверяет учётные данные, НЕ входя в систему. */
    public function validate(array $credentials = []): bool;

    /** Принудительно назначает пользователя на время текущего запроса. */
    public function setUser(Authenticatable $user): void;
}
