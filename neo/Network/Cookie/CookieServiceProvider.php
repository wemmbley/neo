<?php

declare(strict_types=1);

namespace Neo\Network\Cookie;

use Neo\Foundation\Kernel\ServiceProvider;
use Neo\Network\Http\Contracts\RequestContextContract;

final class CookieServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadConfig(base_path('app/Config/Cookie.php'), 'cookie');
        $this->app->singleton(CookieManager::class, fn () => new CookieManager(
            (array) config('cookie', []),
            $this->app->make(RequestContextContract::class),
        ));
    }
}
