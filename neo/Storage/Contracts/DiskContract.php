<?php

namespace Neo\Storage\Contracts;

interface DiskContract
{
    // чтение
    public function get(string $path): string;
    public function json(string $path, bool $assoc = true): mixed;
    public function readStream(string $path);

    // запись
    public function put(string $path, string|UploadedFileContract $contents, array $options = []): bool;
    public function putStream(string $path, $stream, array $options = []): bool;
    public function putFile(string $directory, UploadedFileContract $file, array $options = []): string;
    public function putFileAs(string $directory, UploadedFileContract $file, string $name, array $options = []): string;
    public function append(string $path, string $data): bool;
    public function prepend(string $path, string $data): bool;

    // проверки
    public function exists(string $path): bool;
    public function missing(string $path): bool;
    public function directoryExists(string $path): bool;

    // отдача клиенту
    public function download(string $path, ?string $name = null, array $headers = []): mixed;
    public function response(string $path, ?string $name = null, array $headers = []): mixed;

    // URL
    public function url(string $path): string;
    public function temporaryUrl(string $path, \DateTimeInterface $expiration, array $options = []): string;
    public function temporaryUploadUrl(string $path, \DateTimeInterface $expiration, array $options = []): array;
    public function buildTemporaryUrlsUsing(\Closure $callback): void;

    // метаданные
    public function size(string $path): int;
    public function lastModified(string $path): int;
    public function mimeType(string $path): string;
    public function checksum(string $path, string $algo = 'md5'): string;
    public function path(string $path): string;
    public function getVisibility(string $path): string;
    public function setVisibility(string $path, string $visibility): bool;

    // операции
    public function delete(string|array $paths): bool;
    public function copy(string $from, string $to): bool;
    public function move(string $from, string $to): bool;
    public function copyToDisk(string $path, string $targetDisk, ?string $targetPath = null): bool;
    public function moveToDisk(string $path, string $targetDisk, ?string $targetPath = null): bool;

    // директории
    /** @return string[] */
    public function files(?string $directory = null): array;
    public function allFiles(?string $directory = null): array;
    public function directories(?string $directory = null): array;
    public function allDirectories(?string $directory = null): array;
    public function makeDirectory(string $path): bool;
    public function deleteDirectory(string $path): bool;
}