<?php

namespace Neo\Security\Crypt;

use Neo\Foundation\Kernel\ServiceProvider;
use Neo\Security\Crypt\Commands\GenerateAppKeyCommand;

class CryptServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CryptManager::class, function () {
            $key = env('APP_KEY');

            if(empty($key)) {
                throw new \Exception('
                Приложение не имеет ключ-соль. 
                Безопасность под угрозой. 
                Пожалуйста, выполните php cli key:generate
                ');
            }

            return new CryptManager($key);
        });

        $this->commands([GenerateAppKeyCommand::class,]);
    }
}