<?php

declare(strict_types=1);

namespace Neo\Cache\Stores;

use Neo\Cache\Contracts\PrunableContract;
use Neo\Storage\Contracts\DiskContract;
use Neo\Storage\Exceptions\FileAlreadyExistsException;
use Neo\Storage\Exceptions\FileNotFoundException;
use Neo\Storage\Exceptions\UnableToDeleteException;
use Neo\Storage\Exceptions\UnableToWriteException;

/**
 * Кэш поверх Neo\Storage\Disk. Любой диск (local, memory, свой драйвер) подходит.
 *
 * Раскладка: <directory>/ab/cd/<sha256(prefix . key)>.cache
 * Запись атомарна (это даёт Disk: tmp + rename), читатель не увидит половину файла.
 * add() атомарен благодаря overwrite=false. increment() между процессами не атомарен.
 */
class FileStore extends BasicStore implements PrunableContract
{
    public function __construct(
        private readonly DiskContract $disk,
        private readonly string $directory = 'cache',
        string $prefix = '',
    ) {
        parent::__construct($prefix);

        // flush() сносит директорию целиком: корень диска трогать нельзя
        if (trim($this->directory, '/') === '') {
            throw new \InvalidArgumentException('FileStore directory must not be the disk root.');
        }
    }

    private function path(string $key): string
    {
        $hash = hash('sha256', $this->prefixed($key));

        return trim($this->directory, '/')
            . '/' . substr($hash, 0, 2)
            . '/' . substr($hash, 2, 2)
            . '/' . $hash . '.cache';
    }

    /**
     * @return array{value: mixed, expires: ?int}|null
     */
    private function decode(string $raw): ?array
    {
        $data = @unserialize($raw);

        if (!is_array($data) || !array_key_exists('value', $data) || !array_key_exists('expires', $data)) {
            return null;
        }

        return ['value' => $data['value'], 'expires' => $data['expires']];
    }

    protected function fetch(string $key): ?array
    {
        try {
            $raw = $this->disk->get($this->path($key));
        } catch (FileNotFoundException) {
            return null;
        }

        $entry = $this->decode($raw);

        if ($entry === null) {
            // повреждённый файл: считаем промахом и убираем
            $this->remove($key);
        }

        return $entry;
    }

    protected function write(string $key, mixed $value, ?int $expiresAt): bool
    {
        $payload = $this->encode($key, ['value' => $value, 'expires' => $expiresAt]);

        try {
            return (bool) $this->disk->put($this->path($key), $payload, ['overwrite' => true]);
        } catch (UnableToWriteException) {
            return false;
        }
    }

    protected function writeIfAbsent(string $key, mixed $value, ?int $expiresAt): bool
    {
        // entry() заодно удаляет просроченный файл, иначе он блокировал бы add()
        if ($this->entry($key) !== null) {
            return false;
        }

        $payload = $this->encode($key, ['value' => $value, 'expires' => $expiresAt]);

        try {
            return (bool) $this->disk->put($this->path($key), $payload, ['overwrite' => false]);
        } catch (FileAlreadyExistsException) {
            return false;
        } catch (UnableToWriteException) {
            return false;
        }
    }

    protected function remove(string $key): bool
    {
        try {
            return (bool) $this->disk->delete($this->path($key));
        } catch (FileNotFoundException) {
            return true;
        } catch (UnableToDeleteException) {
            return false;
        }
    }

    public function flush(): bool
    {
        $dir = trim($this->directory, '/');

        if ($this->disk->directoryExists($dir)) {
            $this->disk->deleteDirectory($dir);
        }

        return (bool) $this->disk->makeDirectory($dir);
    }

    public function prune(): int
    {
        $now = $this->now();
        $removed = 0;

        foreach ($this->disk->allFiles(trim($this->directory, '/')) as $file) {
            try {
                $raw = $this->disk->get($file);
            } catch (FileNotFoundException) {
                continue;
            }

            $entry = $this->decode($raw);

            if ($entry === null || ($entry['expires'] !== null && $entry['expires'] <= $now)) {
                try {
                    $this->disk->delete($file);
                    $removed++;
                } catch (FileNotFoundException|UnableToDeleteException) {
                    // удалён параллельно или недоступен: пропускаем
                }
            }
        }

        return $removed;
    }
}