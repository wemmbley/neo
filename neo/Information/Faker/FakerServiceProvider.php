<?php

namespace Neo\Information\Faker;

use Neo\Foundation\Kernel\ServiceProvider;

class FakerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadTranslations(__DIR__ . '/Lang', 'faker');
    }
}