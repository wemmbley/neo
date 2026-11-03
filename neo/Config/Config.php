<?php

declare(strict_types=1);

namespace Neo\Config;

use Neo\Support\Arr;

/**
 * Хранилище настроек с доступом по пути через точку:
 * `config('view.cache_path')`.
 *
 * Файлы настроек лежат в `core/config/*.php`, каждый
 * возвращает массив; имя файла становится первым сегментом
 * пути (`view.php` -> `view.*`).
 */
final class Config
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(private array $items = []) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->items, $key, $default);
    }

    public function has(string $key): bool
    {
        return Arr::has($this->items, $key);
    }

    public function set(string $key, mixed $value): void
    {
        Arr::set($this->items, $key, $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Читает все `*.php` из папки.
     *
     * @return array<string, array<string, mixed>> Имя файла => его настройки. Пустой массив, если папки нет.
     *
     * @throws \UnexpectedValueException Если файл вернул не массив.
     */
    public static function loadDirectory(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $items = [];
        $files = glob(rtrim($directory, '/\\') . '/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $value = require $file;

            if (!is_array($value)) {
                throw new \UnexpectedValueException("Файл настроек {$file} должен возвращать массив.");
            }

            $items[basename($file, '.php')] = $value;
        }

        return $items;
    }
}
