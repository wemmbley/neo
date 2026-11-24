<?php

declare(strict_types=1);

namespace Neo\Security;

use Neo\Foundation\Kernel\ServiceContainer;
use Neo\Foundation\Kernel\ServiceProvider;
use Neo\Information\Cache\Managers\CacheManager;
use Neo\Network\Http\Contracts\RequestContextContract;
use Neo\Network\Session\SessionManager;
use Neo\Security\Crypt\CryptManager;
use Neo\Security\Csrf\CsrfTokenManager;
use Neo\Security\Headers\CspNonce;
use Neo\Security\RateLimiter\RateLimiter;

/** CSRF, ограничитель частоты, nonce для CSP. Настройки: app/Config/Security.php. */
final class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadConfig(base_path('app/Config/Security.php'), 'security');

        $this->app->singleton(CsrfTokenManager::class, static fn (ServiceContainer $c): CsrfTokenManager => new CsrfTokenManager(
            $c->make(CryptManager::class),
            $c->make(SessionManager::class),
            $c->make(RequestContextContract::class),
        ));

        $this->app->singleton(RateLimiter::class, static fn (ServiceContainer $c): RateLimiter => new RateLimiter(
            $c->make(CacheManager::class),
        ));

        $this->app->singleton(CspNonce::class, static fn (ServiceContainer $c): CspNonce => new CspNonce(
            $c->make(RequestContextContract::class),
        ));
    }
}
