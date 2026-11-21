<?php

declare(strict_types=1);

namespace Neo\Foundation\Kernel\Bootstrappers;

use Neo\Foundation\Config\Env;
use Neo\Foundation\Kernel\BootstrapperContract;
use Neo\Foundation\Kernel\Kernel;

/**
 * Шаг 1. Читает `.env` из корня проекта.
 * Настоящие переменные окружения сервера сильнее файла.
 */
final class EnvBootstrapper implements BootstrapperContract
{
    public function bootstrap(Kernel $kernel): void
    {
        $path = $kernel->envPath();

        if ($path !== null) {
            Env::load($path);
        }
    }
}
