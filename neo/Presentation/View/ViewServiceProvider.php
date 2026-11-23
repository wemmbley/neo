<?php

namespace Neo\Presentation\View;

use Neo\Foundation\Kernel\ServiceProvider;

class ViewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadConfig(base_path('app/Config/View.php'), 'view');

        $this->app->singleton(ViewManager::class, function() {
            return new ViewManager(config('view'));
        });
    }
}