<?php

declare(strict_types=1);

namespace Neo\Security\Authentication\Guards;

use Closure;
use Neo\Network\Http\Context\RequestContext;
use Neo\Network\Http\Contracts\RequestContextContract;
use Neo\Network\Http\Request;
use Neo\Security\Authentication\AuthContext;
use Neo\Security\Authentication\Contracts\Authenticatable;
use Neo\Security\Authentication\Contracts\Guard;
use Neo\Security\Authentication\Contracts\UserProvider;

/**
 * Охранник без сессии: пользователя определяет ваша функция по самому запросу
 * (Bearer-токен, API-ключ, подписанная ссылка). Подходит для группы `api`.
 *
 *   Auth::viaRequest('api', function (Request $request, UserProvider $users): ?Authenticatable {
 *       $token = $request->bearerToken();
 *
 *       return $token === null ? null : $users->retrieveByCredentials(['api_token' => hash('sha256', $token)]);
 *   });
 */
final class RequestGuard implements Guard
{
    public function __construct(
        private readonly string $name,
        private readonly Closure $callback,
        private readonly UserProvider $provider,
        private readonly AuthContext $context,
        private readonly RequestContextContract $requests,
    ) {}

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function user(): ?Authenticatable
    {
        if ($this->context->resolved($this->name)) {
            return $this->context->get($this->name);
        }

        if (!$this->requests instanceof RequestContext || !$this->requests->isEntered()) {
            return null;
        }

        $request = $this->requests->request();
        $user = $request instanceof Request ? ($this->callback)($request, $this->provider) : null;
        $user = $user instanceof Authenticatable ? $user : null;

        $this->context->set($this->name, $user);

        return $user;
    }

    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    /** @param array<string, mixed> $credentials ['request' => Request] */
    public function validate(array $credentials = []): bool
    {
        $request = $credentials['request'] ?? null;

        return $request instanceof Request && ($this->callback)($request, $this->provider) instanceof Authenticatable;
    }

    public function setUser(Authenticatable $user): void
    {
        $this->context->set($this->name, $user);
    }
}
