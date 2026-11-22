<?php

namespace Neo\Information\Cache\Providers;

use Neo\Foundation\Kernel\ServiceProvider;
use Neo\Information\Cache\Managers\CacheManager;
use Neo\Information\Cache\Transfers\CacheTransfer;
use Neo\Information\Persistence\Storage\Managers\StorageManager;

class CacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadConfig(base_path('app/Config/Cache.php'), 'cache');

        $this->app->singleton(CacheManager::class, function ($app) {
            $config = new CacheTransfer(config('cache'));

            return new CacheManager(
                $config,
                kernel(StorageManager::class),
            );
        });
    }
}