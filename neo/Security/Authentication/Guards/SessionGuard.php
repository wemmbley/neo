<?php

declare(strict_types=1);

namespace Neo\Security\Authentication\Guards;

use LogicException;
use Neo\Network\Session\SessionManager;
use Neo\Security\Authentication\AuthContext;
use Neo\Security\Authentication\Contracts\Authenticatable;
use Neo\Security\Authentication\Contracts\Guard;
use Neo\Security\Authentication\Contracts\UserProvider;

/**
 * Охранник на сессии: в сессии лежит только id пользователя, сам пользователь
 * подгружается провайдером один раз за запрос. Требует middleware `session` (группа web).
 *
 * Вход меняет идентификатор сессии (защита от фиксации сессии), выход его уничтожает.
 */
final class SessionGuard implements Guard
{
    public function __construct(
        private readonly string $name,
        private readonly UserProvider $provider,
        private readonly SessionManager $session,
        private readonly AuthContext $context,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

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

        # Сессия не открыта (маршрут вне группы web, консоль): ответ «никто» не запоминаем,
        # иначе он «прилипнет», даже если сессию откроют позже.
        if (!$this->sessionReady()) {
            return null;
        }

        $id = $this->session->get($this->sessionKey());
        $user = $id === null ? null : $this->provider->retrieveById($id);

        $this->context->set($this->name, $user);

        return $user;
    }

    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    public function validate(array $credentials = []): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        return $user !== null && $this->provider->validateCredentials($user, $credentials);
    }

    /** Ищет пользователя по учётным данным и, если пароль верен, впускает его. */
    public function attempt(array $credentials = []): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        if ($user === null || !$this->provider->validateCredentials($user, $credentials)) {
            return false;
        }

        $this->login($user);

        return true;
    }

    public function login(Authenticatable $user): void
    {
        if (!$this->sessionReady()) {
            throw new LogicException('Для входа нужна сессия: маршрут должен проходить через middleware session (группа web).');
        }

        $this->session->regenerate();
        $this->session->put($this->sessionKey(), $user->getAuthIdentifier());
        $this->context->set($this->name, $user);
    }

    public function loginUsingId(int|string $id): ?Authenticatable
    {
        $user = $this->provider->retrieveById($id);

        if ($user !== null) {
            $this->login($user);
        }

        return $user;
    }

    public function logout(): void
    {
        if ($this->sessionReady()) {
            $this->session->forget($this->sessionKey());
            $this->session->regenerate(true);
        }

        # Запоминаем «никто не вошёл», чтобы дальше в этом запросе не читать сессию заново.
        $this->context->set($this->name, null);
    }

    public function setUser(Authenticatable $user): void
    {
        $this->context->set($this->name, $user);
    }

    private function sessionKey(): string
    {
        return 'auth.login.' . $this->name;
    }

    private function sessionReady(): bool
    {
        try {
            return $this->session->isStarted();
        } catch (LogicException) {
            # Нет контекста запроса (консоль).
            return false;
        }
    }
}
