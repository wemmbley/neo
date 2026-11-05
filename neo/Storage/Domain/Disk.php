<?php

declare(strict_types=1);

namespace Neo\Storage\Domain;

use Neo\Storage\Contracts\DiskContract;
use Neo\Storage\Contracts\DriverContract;
use Neo\Storage\Contracts\ShouldProvide\ShouldProvideLocalPathContract;
use Neo\Storage\Contracts\ShouldProvide\ShouldProvideTemporaryUrlContract;
use Neo\Storage\Contracts\ShouldProvide\ShouldProvideUrlContract;
use Neo\Storage\Contracts\StorageManagerContract;
use Neo\Storage\Contracts\UploadedFileContract;
use Neo\Storage\Enums\Visibility;
use Neo\Storage\Exceptions\PathTraversalException;
use Neo\Storage\Exceptions\StorageException;
use Neo\Storage\Exceptions\UnableToReadException;
use Neo\Storage\Exceptions\UnableToWriteException;
use Neo\Storage\Exceptions\UnsupportedOperationException;
use Neo\Http\Response\StorageResponse;

/**
 * Диск: пользовательский API поверх драйвера.
 *
 * Политика ошибок:
 *  - методы, возвращающие bool, при throw=false возвращают false вместо исключения;
 *  - методы с не-bool результатом (get, size, putFile...) бросают всегда,
 *    потому что тип результата не может сигнализировать о сбое;
 *  - PathTraversalException и UnsupportedOperationException бросаются ВСЕГДА
 *    (это атака или ошибка программиста, глотать их нельзя);
 *  - report=true отправляет исключение в репортёр (логгер); атаки на пути
 *    репортятся всегда, если репортёр задан.
 */
class Disk implements DiskContract
{
    /** Типы, которые безопасно показывать в браузере inline. Остальное - только скачивание. */
    private const INLINE_MIME = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
        'application/pdf', 'text/plain',
    ];

    private ?\Closure $temporaryUrlCallback = null;

    /**
     * @param \Closure(StorageException, array): void|null $reporter
     */
    public function __construct(
        private readonly DriverContract $driver,
        private readonly string $name = 'default',
        private readonly bool $throw = false,
        private readonly bool $report = false,
        private readonly bool $serve = false,
        private readonly ?StorageManagerContract $manager = null,
        private readonly ?\Closure $reporter = null,
    ) {
    }

    /** Бросает ли диск исключения на "мягких" сбоях (флаг throw конфига). */
    public function throws(): bool
    {
        return $this->throw;
    }

    // ---------------------------------------------------------------- чтение

    public function get(string $path): string
    {
        return $this->run(fn (): string => $this->driver->read($path));
    }

    public function json(string $path, bool $assoc = true): mixed
    {
        return $this->run(function () use ($path, $assoc): mixed {
            try {
                return json_decode($this->driver->read($path), $assoc, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new UnableToReadException("File [{$path}] does not contain valid JSON.");
            }
        });
    }

    public function readStream(string $path)
    {
        return $this->run(fn () => $this->driver->readStream($path));
    }

    // ---------------------------------------------------------------- запись

    public function put(string $path, string|UploadedFileContract $contents, array $options = []): bool
    {
        return $this->attempt(function () use ($path, $contents, $options): void {
            if ($contents instanceof UploadedFileContract) {
                $this->writeUpload($path, $contents, $options);

                return;
            }

            $this->driver->write($path, $contents, $options);
        });
    }

    public function putStream(string $path, $stream, array $options = []): bool
    {
        return $this->attempt(fn () => $this->driver->writeStream($path, $stream, $options));
    }

    public function putFile(string $directory, UploadedFileContract $file, array $options = []): string
    {
        return $this->putFileAs($directory, $file, $file->hashName(), $options);
    }

    public function putFileAs(string $directory, UploadedFileContract $file, string $name, array $options = []): string
    {
        return $this->run(function () use ($directory, $file, $name, $options): string {
            $path = PathNormalizer::join($directory, PathNormalizer::filename($name));
            $this->writeUpload($path, $file, $options);

            return $path;
        });
    }

    public function append(string $path, string $data): bool
    {
        return $this->attempt(fn () => $this->driver->append($path, $data));
    }

    public function prepend(string $path, string $data): bool
    {
        return $this->attempt(fn () => $this->driver->prepend($path, $data));
    }

    // ---------------------------------------------------------------- проверки

    public function exists(string $path): bool
    {
        return $this->run(fn (): bool => $this->driver->fileExists($path));
    }

    public function missing(string $path): bool
    {
        return !$this->exists($path);
    }

    public function directoryExists(string $path): bool
    {
        return $this->run(fn (): bool => $this->driver->directoryExists($path));
    }

    // ---------------------------------------------------------------- отдача клиенту

    /** Всегда скачивание (Content-Disposition: attachment). */
    public function download(string $path, ?string $name = null, array $headers = []): StorageResponse
    {
        return $this->serveFile($path, $name, $headers, false);
    }

    /** Inline только для безопасных типов; HTML/SVG и прочее принудительно скачивается (защита от XSS). */
    public function response(string $path, ?string $name = null, array $headers = []): StorageResponse
    {
        return $this->serveFile($path, $name, $headers, true);
    }

    // ---------------------------------------------------------------- URL

    public function url(string $path): string
    {
        return $this->run(function () use ($path): string {
            if (!$this->serve) {
                throw new UnsupportedOperationException("Disk [{$this->name}] is not served (serve = false).");
            }

            if (!$this->driver instanceof ShouldProvideUrlContract) {
                throw new UnsupportedOperationException("Driver of disk [{$this->name}] does not provide URLs.");
            }

            return $this->driver->url($path);
        });
    }

    public function temporaryUrl(string $path, \DateTimeInterface $expiration, array $options = []): string
    {
        return $this->run(function () use ($path, $expiration, $options): string {
            if ($this->temporaryUrlCallback !== null) {
                return (string) ($this->temporaryUrlCallback)($path, $expiration, $options);
            }

            if (!$this->driver instanceof ShouldProvideTemporaryUrlContract) {
                throw new UnsupportedOperationException("Disk [{$this->name}] does not support temporary URLs.");
            }

            return $this->driver->temporaryUrl($path, $expiration, $options);
        });
    }

    public function temporaryUploadUrl(string $path, \DateTimeInterface $expiration, array $options = []): array
    {
        return $this->run(function () use ($path, $expiration, $options): array {
            if (!$this->driver instanceof ShouldProvideTemporaryUrlContract) {
                throw new UnsupportedOperationException("Disk [{$this->name}] does not support temporary upload URLs.");
            }

            return $this->driver->temporaryUploadUrl($path, $expiration, $options);
        });
    }

    /** Своя генерация подписанных ссылок: fn(string $path, \DateTimeInterface $expiration, array $options): string. */
    public function buildTemporaryUrlsUsing(\Closure $callback): void
    {
        $this->temporaryUrlCallback = $callback;
    }

    // ---------------------------------------------------------------- метаданные

    public function size(string $path): int
    {
        return $this->run(fn (): int => $this->driver->size($path));
    }

    public function lastModified(string $path): int
    {
        return $this->run(fn (): int => $this->driver->lastModified($path));
    }

    public function mimeType(string $path): string
    {
        return $this->run(fn (): string => $this->driver->mimeType($path));
    }

    public function checksum(string $path, string $algo = 'md5'): string
    {
        return $this->run(fn (): string => $this->driver->checksum($path, $algo));
    }

    /** Абсолютный путь на файловой системе (только драйверы, которые это умеют). */
    public function path(string $path): string
    {
        return $this->run(function () use ($path): string {
            if (!$this->driver instanceof ShouldProvideLocalPathContract) {
                throw new UnsupportedOperationException("Disk [{$this->name}] has no local path.");
            }

            return $this->driver->path($path);
        });
    }

    public function getVisibility(string $path): string
    {
        return $this->run(fn (): string => $this->driver->visibility($path)->value);
    }

    public function setVisibility(string $path, string $visibility): bool
    {
        return $this->attempt(fn () => $this->driver->setVisibility($path, Visibility::resolve($visibility, Visibility::Private)));
    }

    // ---------------------------------------------------------------- операции

    /** Удаляет один или несколько файлов. Пытается удалить все, false - если хоть один не удался. */
    public function delete(string|array $paths): bool
    {
        $ok = true;

        foreach ((array) $paths as $path) {
            $ok = $this->attempt(fn () => $this->driver->delete($path)) && $ok;
        }

        return $ok;
    }

    /** Копирование НЕ перезаписывает существующий файл (FileAlreadyExistsException). */
    public function copy(string $from, string $to): bool
    {
        return $this->attempt(fn () => $this->driver->copy($from, $to));
    }

    /** Перемещение НЕ перезаписывает существующий файл (FileAlreadyExistsException). */
    public function move(string $from, string $to): bool
    {
        return $this->attempt(fn () => $this->driver->move($from, $to));
    }

    public function copyToDisk(string $path, string $targetDisk, ?string $targetPath = null): bool
    {
        // ошибка конфигурации не подавляется флагом throw
        $target = $this->run(fn (): DiskContract => $this->manager?->disk($targetDisk)
            ?? throw new UnsupportedOperationException('This disk was created without a storage manager.'));

        return $this->attempt(function () use ($path, $target, $targetDisk, $targetPath): void {
            $stream = $this->driver->readStream($path);

            try {
                if (!$target->putStream($targetPath ?? $path, $stream)) {
                    throw new UnableToWriteException("Unable to copy [{$path}] to disk [{$targetDisk}].");
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        });
    }

    private function targetDisk(string $name): DiskContract
    {
        return $this->run(fn (): DiskContract => $this->manager?->disk($name)
            ?? throw new UnsupportedOperationException('This disk was created without a storage manager.'));
    }

    /** Исходник удаляется только после успешной записи на целевой диск. */
    public function moveToDisk(string $path, string $targetDisk, ?string $targetPath = null): bool
    {
        return $this->copyToDisk($path, $targetDisk, $targetPath) && $this->delete($path);
    }

    // ---------------------------------------------------------------- директории

    public function files(?string $directory = null): array
    {
        return $this->listing($directory, false, false);
    }

    public function allFiles(?string $directory = null): array
    {
        return $this->listing($directory, true, false);
    }

    public function directories(?string $directory = null): array
    {
        return $this->listing($directory, false, true);
    }

    public function allDirectories(?string $directory = null): array
    {
        return $this->listing($directory, true, true);
    }

    public function makeDirectory(string $path): bool
    {
        return $this->attempt(fn () => $this->driver->makeDirectory($path));
    }

    public function deleteDirectory(string $path): bool
    {
        return $this->attempt(fn () => $this->driver->deleteDirectory($path));
    }

    // ---------------------------------------------------------------- внутреннее

    /** @return string[] */
    private function listing(?string $directory, bool $recursive, bool $directoriesOnly): array
    {
        return $this->run(function () use ($directory, $recursive, $directoriesOnly): array {
            $result = [];

            // Драйвер помечает директории завершающим "/".
            foreach ($this->driver->listContents($directory ?? '', $recursive) as $entry) {
                if (str_ends_with($entry, '/') === $directoriesOnly) {
                    $result[] = rtrim($entry, '/');
                }
            }

            return $result;
        });
    }

    private function writeUpload(string $path, UploadedFileContract $file, array $options): void
    {
        if (!$file->isValid()) {
            throw new UnableToWriteException('Upload failed: ' . $file->getErrorMessage());
        }

        $stream = $file->stream();

        try {
            $this->driver->writeStream($path, $stream, $options);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function serveFile(string $path, ?string $name, array $headers, bool $inline): StorageResponse
    {
        return $this->run(function () use ($path, $name, $headers, $inline): StorageResponse {
            $mime = $this->driver->mimeType($path);
            $inline = $inline && (in_array($mime, self::INLINE_MIME, true)
                    || str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/'));

            return new StorageResponse(200, array_merge([
                'Content-Type' => $mime,
                'Content-Length' => (string) $this->driver->size($path),
                'Content-Disposition' => StorageResponse::disposition($inline ? 'inline' : 'attachment', $name ?? basename($path)),
                'Last-Modified' => gmdate('D, d M Y H:i:s', $this->driver->lastModified($path)) . ' GMT',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ], $headers), $this->driver->readStream($path));
        });
    }

    /** Выполняет операцию, репортит сбой и пробрасывает исключение дальше. */
    private function run(\Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (StorageException $e) {
            if ($this->reporter !== null && ($this->report || $e instanceof PathTraversalException)) {
                ($this->reporter)($e, ['disk' => $this->name]);
            }

            throw $e;
        }
    }

    /** То же, но для bool-методов: при throw=false "мягкий" сбой превращается в false. */
    private function attempt(\Closure $operation): bool
    {
        try {
            $this->run($operation);

            return true;
        } catch (PathTraversalException | UnsupportedOperationException $e) {
            throw $e; // атака или ошибка программиста - никогда не глотаем
        } catch (StorageException $e) {
            if ($this->throw) {
                throw $e;
            }

            return false;
        }
    }
}