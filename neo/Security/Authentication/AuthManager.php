<?php

declare(strict_types=1);

namespace Neo\Security\Authentication;

use Closure;
use InvalidArgumentException;
use Neo\Foundation\Kernel\ServiceContainer;
use Neo\Network\Http\Contracts\RequestContextContract;
use Neo\Network\Session\SessionManager;
use Neo\Security\Authentication\Contracts\Guard;
use Neo\Security\Authentication\Contracts\UserProvider;
use Neo\Security\Authentication\Guards\RequestGuard;
use Neo\Security\Authentication\Guards\SessionGuard;
use Neo\Security\Authentication\Providers\ModelUserProvider;
use Neo\Security\Crypt\CryptManager;

/**
 * Реестр охранников. Настройки: app/Config/Auth.php.
 *
 * Хранит только описания и созданные охранники, данные запроса живут в AuthContext,
 * поэтому один экземпляр на процесс безопасен для Swoole. Все неизвестные вызовы
 * (`user()`, `check()`, `attempt()`, `login()`, `logout()` ...) уходят охраннику по умолчанию.
 *
 * @mixin SessionGuard
 */
final class AuthManager
{
    /** @var array<string, Guard> */
    private array $guards = [];

    /** @var array<string, UserProvider> */
    private array $providers = [];

    /** @var array<string, Closure> */
    private array $requestCallbacks = [];

    public function __construct(
        private readonly ServiceContainer $container,
        private readonly array $config,
    ) {}

    public function defaultGuard(): string
    {
        return (string) ($this->config['defaults']['guard'] ?? 'web');
    }

    public function guard(?string $name = null): Guard
    {
        $name ??= $this->defaultGuard();

        return $this->guards[$name] ??= $this->build($name);
    }

    /** Подключает охранника типа `request` (токены, API-ключи): `Auth::viaRequest('api', fn (Request $r) => ...)`. */
    public function viaRequest(string $guard, Closure $callback): static
    {
        $this->requestCallbacks[$guard] = $callback;
        unset($this->guards[$guard]);

        return $this;
    }

    public function provider(?string $name = null): UserProvider
    {
        $name ??= (string) array_key_first((array) ($this->config['providers'] ?? []));

        return $this->providers[$name] ??= $this->buildProvider($name);
    }

    public function __call(string $method, array $arguments): mixed
    {
        return $this->guard()->{$method}(...$arguments);
    }

    private function build(string $name): Guard
    {
        $definition = $this->config['guards'][$name] ?? throw new InvalidArgumentException("Охранник [{$name}] не описан в app/Config/Auth.php.");
        $provider = $this->provider($definition['provider'] ?? null);
        $context = $this->container->make(AuthContext::class);

        return match ($definition['driver'] ?? 'session') {
            'session' => new SessionGuard($name, $provider, $this->container->make(SessionManager::class), $context),
            'request' => new RequestGuard(
                $name,
                $this->requestCallbacks[$name] ?? static fn (): null => null,
                $provider,
                $context,
                $this->container->make(RequestContextContract::class),
            ),
            default => throw new InvalidArgumentException("Драйвер охранника [{$definition['driver']}] не поддерживается."),
        };
    }

    private function buildProvider(string $name): UserProvider
    {
        $definition = $this->config['providers'][$name] ?? throw new InvalidArgumentException("Провайдер пользователей [{$name}] не описан в app/Config/Auth.php.");

        return match ($definition['driver'] ?? 'model') {
            'model' => new ModelUserProvider((string) $definition['model'], $this->container->make(CryptManager::class)),
            default => throw new InvalidArgumentException("Драйвер провайдера [{$definition['driver']}] не поддерживается."),
        };
    }
}
