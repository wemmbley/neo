<?php

declare(strict_types=1);

namespace Neo\Foundation\Kernel;

interface DependencyResolverContract
{
    public function resolve(
        ServiceContainer $container,
        string $abstract,
        array $parameters = [],
    ): mixed;

    public function call(
        ServiceContainer $container,
        callable|array|string $callback,
        array $parameters = [],
    ): mixed;
}
