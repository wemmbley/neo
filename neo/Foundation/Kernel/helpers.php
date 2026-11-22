<?php

use Neo\Foundation\Kernel\Events\Dispatcher;
use Neo\Foundation\Kernel\Kernel;

/**
 * Kernel или объект из контейнера: `kernel()`, `kernel(Router::class)`.
 *
 * @param array<string, mixed> $parameters
 */
if (!function_exists('kernel')) {
    function kernel(
        ?string $abstract = null,
        array $parameters = []
    ): mixed
    {
        $kernel = Kernel::getInstance();

        return $abstract === null ? $kernel : $kernel->make($abstract, $parameters);
    }
}

/**
 * Корневая директория проекта или путь внутри неё.
 */
if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return Kernel::getInstance()->basePath($path);
    }
}

/**
 * Профилировщик контейнера: печатает статистику вызовов.
 * Работает при APP_PROFILER=true (config app.profiler).
 */
if (!function_exists('profiler')) {
    function profiler(): void
    {
        $stats = kernel()->getContainer()->getProfiler()?->getStatistics();

        function_exists('dd') ? dd($stats) : var_dump($stats);
    }
}

if (!function_exists('event')) {
    /** `event(new PriceChanged($p))`, `event('user.created', [$user])`. */
    function event(string|object $event, mixed $payload = [], bool $halt = false): mixed
    {
        return kernel(Dispatcher::class)->dispatch($event, $payload, $halt);
    }
}

