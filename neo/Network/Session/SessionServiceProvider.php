<?php

declare(strict_types=1);

namespace Neo\Network\Session;

use Neo\Foundation\Kernel\ServiceProvider;
use Neo\Information\Persistence\Database\DatabaseManager;
use Neo\Information\Persistence\Storage\Storage;
use Neo\Network\Cookie\CookieManager;
use Neo\Network\Http\Contracts\RequestContextContract;
use Neo\Network\Session\Contracts\SessionDriverContract;
use Neo\Network\Session\Drivers\ArraySessionDriver;
use Neo\Network\Session\Drivers\DatabaseSessionDriver;
use Neo\Network\Session\Drivers\FileSessionDriver;
use Neo\Security\Crypt\CryptManager;
use RuntimeException;

final class SessionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadConfig(base_path('app/Config/Session.php'), 'session');

        $this->app->singleton(SessionDriverContract::class, function () {
            $config = (array) config('session', []);
            return match ($config['driver'] ?? 'database') {
                'array' => new ArraySessionDriver((int) ($config['lifetime'] ?? 120) * 60),
                'file' => new FileSessionDriver(
                    Storage::build(['driver' => 'local', 'root' => (string) ($config['files'] ?? base_path('app/Storage/framework/sessions')), 'throw' => true]),
                    '',
                    (int) ($config['lifetime'] ?? 120) * 60,
                ),
                'database' => new DatabaseSessionDriver(
                    $this->app->make(DatabaseManager::class),
                    (string) ($config['table'] ?? 'sessions'),
                    $config['connection'] ?? null,
                    (int) ($config['lifetime'] ?? 120) * 60,
                ),
                default => throw new RuntimeException('Unsupported session driver [' . ($config['driver'] ?? '') . '].'),
            };
        });

        $this->app->singleton(SessionManager::class, fn () => new SessionManager(
            (array) config('session', []),
            $this->app->make(SessionDriverContract::class),
            $this->app->make(CookieManager::class),
            $this->app->make(RequestContextContract::class),
            (bool) config('session.encrypt', false) ? $this->app->make(CryptManager::class) : null,
        ));
    }
}
