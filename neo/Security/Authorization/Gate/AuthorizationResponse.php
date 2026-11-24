<?php

declare(strict_types=1);

namespace Neo\Security\Authorization\Gate;

use Neo\Security\Authorization\AuthorizationException;

/**
 * Результат проверки: разрешено/запрещено плюс причина и HTTP-статус отказа.
 * Политика может вернуть bool или этот объект:
 *
 *   return $user->id === $post->userId ? true : AuthorizationResponse::deny('Это не ваша запись.');
 *   return AuthorizationResponse::denyAsNotFound();   // прятать существование ресурса
 */
final class AuthorizationResponse
{
    private function __construct(
        private readonly bool $allowed,
        private readonly string $message = '',
        private readonly ?int $status = null,
    ) {}

    public static function allow(string $message = ''): self
    {
        return new self(true, $message);
    }

    public static function deny(string $message = ''): self
    {
        return new self(false, $message);
    }

    public static function denyWithStatus(int $status, string $message = ''): self
    {
        return new self(false, $message, $status);
    }

    public static function denyAsNotFound(string $message = ''): self
    {
        return new self(false, $message, 404);
    }

    public function allowed(): bool
    {
        return $this->allowed;
    }

    public function denied(): bool
    {
        return !$this->allowed;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function status(): ?int
    {
        return $this->status;
    }

    /** Бросает AuthorizationException, если запрещено. */
    public function authorize(): self
    {
        if ($this->denied()) {
            throw new AuthorizationException($this->message, $this->status ?? 403);
        }

        return $this;
    }

    /** @return array{allowed: bool, message: string, status: ?int} */
    public function toArray(): array
    {
        return ['allowed' => $this->allowed, 'message' => $this->message, 'status' => $this->status];
    }
}
