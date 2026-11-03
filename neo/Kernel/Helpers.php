<?php

use Neo\Kernel\Application;

/**
 * Приложение или объект из контейнера: `app()`, `app(Router::class)`.
 *
 * @param array<string, mixed> $parameters
 */
if (!function_exists('app')) {
    function app(
        ?string $abstract = null,
        array $parameters = []
    ): mixed
    {
        $app = Application::getInstance();

        return $abstract === null ? $app : $app->make($abstract, $parameters);
    }
}

/**
 * Получить корневую директорию проекта.
 *
 * @param string $path
 * @return string
 */
if(!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $base = dirname(__DIR__, 2);

        return $path === ''
            ? $base
            : $base . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}

/**
 * Профилировщик приложения. Из места вызова бросает статистику вызовов.
 *
 * @return void
 */
if(!function_exists('profiler')) {
    function profiler(): void
    {
        dd(app()->getContainer()->getProfiler()?->getStatistics());
    }
}