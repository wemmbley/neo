<?php

namespace Neo\Storage\Contracts;

use Neo\Storage\Enums\Visibility;

interface DriverContract
{
    // чтение
    public function read(string $path): string;
    /** @return resource */
    public function readStream(string $path);

    // запись
    public function write(string $path, string $contents, array $options = []): void;
    /** @param resource $stream */
    public function writeStream(string $path, $stream, array $options = []): void;
    public function append(string $path, string $contents): void;
    public function prepend(string $path, string $contents): void;

    // проверки
    public function fileExists(string $path): bool;
    public function directoryExists(string $path): bool;

    // метаданные
    public function size(string $path): int;
    public function lastModified(string $path): int;
    public function mimeType(string $path): string;
    public function checksum(string $path, string $algo = 'md5'): string;
    public function visibility(string $path): Visibility;
    public function setVisibility(string $path, Visibility $visibility): void;

    // операции над файлами
    public function delete(string $path): void;
    public function copy(string $from, string $to): void;
    public function move(string $from, string $to): void;

    // директории
    /** @return iterable<string> */
    public function listContents(string $directory, bool $recursive = false): iterable;
    public function makeDirectory(string $path): void;
    public function deleteDirectory(string $path): void;
}