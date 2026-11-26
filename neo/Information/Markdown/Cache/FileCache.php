<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Cache;

use JsonException;
use Neo\Information\Markdown\Transfer\CacheTransfer;

/**
 * Файловый кэш разобранных документов (JSON, без unserialize).
 * Запись атомарная: временный файл + rename.
 */
final class FileCache
{
    public function __construct(
        private readonly CacheTransfer $config,
    ) {}

    /**
     * @param callable(): list<array<string, mixed>> $resolve
     * @return list<array<string, mixed>>
     */
    public function remember(string $key, callable $resolve): array
    {
        if (!$this->config->enabled) {
            return $resolve();
        }

        $file = $this->file($key);
        $cached = $this->read($file);

        if ($cached !== null) {
            return $cached;
        }

        $value = $resolve();
        $this->write($file, $value);

        return $value;
    }

    /**
     * Удаляет все файлы кэша. Возвращает количество удалённых.
     */
    public function flush(): int
    {
        $removed = 0;

        foreach (glob($this->directory() . '/*.json') ?: [] as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function directory(): string
    {
        return rtrim($this->config->path, '/\\');
    }

    private function file(string $key): string
    {
        return $this->directory() . '/' . $key . '.json';
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function read(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        if ($this->config->ttl > 0 && (int) filemtime($file) + $this->config->ttl < time()) {
            return null;
        }

        $json = @file_get_contents($file);

        if ($json === false) {
            return null;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @param list<array<string, mixed>> $value
     */
    private function write(string $file, array $value): void
    {
        $directory = dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        try {
            $json = json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            );
        } catch (JsonException) {
            return;
        }

        $temporary = @tempnam($directory, '.tmp');

        if ($temporary === false) {
            return;
        }

        if (@file_put_contents($temporary, $json) === false || !@rename($temporary, $file)) {
            @unlink($temporary);
        }
    }
}
