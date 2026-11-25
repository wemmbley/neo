<?php

namespace Neo\Information\Persistence\Storage\Providers;

use Neo\Foundation\Kernel\ServiceProvider;
use Neo\Information\Persistence\Storage\Commands\StorageLinkCommand;
use Neo\Information\Persistence\Storage\Managers\StorageManager;
use Neo\Information\Persistence\Storage\Transfers\StorageTransfer;

class StorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadConfig(base_path('app/Config/Storage.php'), 'storage');

        $this->app->singleton(StorageManager::class, function() {
            $storageTransfer = new StorageTransfer(config('storage'));

            return new StorageManager($storageTransfer);
        });

        $this->commands([
            StorageLinkCommand::class,
        ]);
    }
}