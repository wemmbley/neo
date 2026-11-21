<?php

declare(strict_types=1);

namespace Neo\Kernel\Bootstrappers;

use Neo\Config\Env;
use Neo\Kernel\BootstrapperContract;
use Neo\Kernel\Kernel;

/**
 * Шаг 1. Читает `.env` из корня проекта.
 * Настоящие переменные окружения сервера сильнее файла.
 */
final class EnvBootstrapper implements BootstrapperContract
{
    public function bootstrap(Kernel $kernel): void
    {
        Env::load($kernel->basePath('.env'));
    }
}
