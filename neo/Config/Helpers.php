<?php

use Neo\Config\Config;
use Neo\Config\Env;

/**
 * Значение из `.env` или окружения сервера:
 * `env('APP_DEBUG', false)`, `env('db.host', '127.0.0.1')`.
 * Слова true, false, null и empty превращаются
 * в значения соответствующих типов.
 */
if (!function_exists('env')) {
    function env(
        string $key,
        mixed $default = null
    ): mixed
    {
        return Env::get($key, $default);
    }
}

/**
 * Настройка по пути (`config('view.cache_path')`),
 * все настройки (`config()`)
 * или запись (`config(['app.debug' => true])`).
 */
if (!function_exists('config')) {
    function config(
        string|array|null $key = null,
        mixed $default = null
    ): mixed
    {
        $config = app(Config::class);

        if ($key === null) {
            return $config;
        }

        if (is_array($key)) {
            foreach ($key as $path => $value) {
                $config->set((string) $path, $value);
            }

            return null;
        }

        return $config->get($key, $default);
    }
}