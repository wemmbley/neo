<?php

namespace Neo\Storage\Providers;

use Neo\Kernel\ServiceProvider;
use Neo\Storage\Managers\StorageManager;

class StorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StorageManager::class);
    }
}