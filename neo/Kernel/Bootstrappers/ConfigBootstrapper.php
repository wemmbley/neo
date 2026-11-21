<?php

declare(strict_types=1);

namespace Neo\Kernel\Bootstrappers;

use Neo\Config\Config;
use Neo\Kernel\BootstrapperContract;
use Neo\Kernel\Kernel;
use Neo\Kernel\ProfilerDependencyResolver;

/**
 * Шаг 2. Читает `core/config/*.php` (Env уже загружен, поэтому
 * в конфигах работает env()) и кладёт Config в контейнер.
 * При `app.profiler = true` оборачивает резолвер в профилировщик.
 */
final class ConfigBootstrapper implements BootstrapperContract
{
    public function bootstrap(Kernel $kernel): void
    {
        $container = $kernel->getContainer();

        $config = new Config(Config::loadDirectory($kernel->configPath()));

        $container->instance(Config::class, $config);

        if ((bool) $config->get('app.profiler', false)) {
            $container->setResolver(
                new ProfilerDependencyResolver($container->getResolver())
            );
        }
    }
}
