<?php

declare(strict_types=1);

namespace Neo\Foundation\Kernel;

/**
 * Бутстраппер: первая, строго упорядоченная инъекция в контейнер
 * (до сервис-провайдеров). Создаётся самим контейнером.
 */
interface BootstrapperContract
{
    public function bootstrap(Kernel $kernel): void;
}
