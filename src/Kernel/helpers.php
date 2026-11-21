<?php

use Neo\Kernel\Kernel;

/**
 * Kernel или объект из контейнера: `app()`, `app(Router::class)`.
 *
 * @param array<string, mixed> $parameters
 */
if (!function_exists('app')) {
    function app(
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
        $stats = app()->getContainer()->getProfiler()?->getStatistics();

        function_exists('dd') ? dd($stats) : var_dump($stats);
    }
}
