<?php

namespace Neo\Information\Validator;

use Neo\Foundation\Kernel\ServiceProvider;

class ValidatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadTranslations(__DIR__ . '/Lang', 'validator');
    }
}