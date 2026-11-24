<?php

declare(strict_types=1);

namespace Neo\Security\Authentication;

use Neo\Network\Http\Context\RequestContext;
use Neo\Network\Http\Contracts\RequestContextContract;
use Neo\Security\Authentication\Contracts\Authenticatable;

/**
 * Где живёт текущий пользователь. Внутри HTTP-запроса это RequestContext
 * (запросы Swoole не смешиваются), вне запроса (CLI, сидеры, тесты) обычный массив.
 *
 * Запоминается и «никто не вошёл», чтобы не ходить в БД повторно за один запрос.
 */
final class AuthContext
{
    private const string PREFIX = 'auth.user.';

    /** @var array<string, array{0: ?Authenticatable}> */
    private array $detached = [];

    public function __construct(private readonly RequestContextContract $context) {}

    public function resolved(string $guard): bool
    {
        if ($this->inRequest()) {
            return $this->context->get(self::PREFIX . $guard) !== null;
        }

        return isset($this->detached[$guard]);
    }

    public function get(string $guard): ?Authenticatable
    {
        $slot = $this->inRequest()
            ? $this->context->get(self::PREFIX . $guard)
            : ($this->detached[$guard] ?? null);

        return $slot[0] ?? null;
    }

    public function set(string $guard, ?Authenticatable $user): void
    {
        $slot = [$user];

        if ($this->inRequest()) {
            $this->context->set(self::PREFIX . $guard, $slot);

            return;
        }

        $this->detached[$guard] = $slot;
    }

    public function forget(string $guard): void
    {
        if ($this->inRequest()) {
            $this->context->forget(self::PREFIX . $guard);

            return;
        }

        unset($this->detached[$guard]);
    }

    private function inRequest(): bool
    {
        return $this->context instanceof RequestContext && $this->context->isEntered();
    }
}
