<?php

declare(strict_types=1);

namespace Neo\Network\Http;

use Neo\Foundation\Kernel\ServiceProvider;
use Neo\Network\Http\Contracts\RequestContextContract;
use Neo\Network\Http\Testing\FiberRequestContext;

final class HttpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RequestContextContract::class, FiberRequestContext::class);
    }
}
