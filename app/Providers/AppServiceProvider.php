<?php

declare(strict_types=1);

namespace App\Providers;

use Neo\Foundation\Kernel\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // $this->app->singleton(Foo::class, FooImpl::class);
    }

    public function boot(): void
    {
    }
}
