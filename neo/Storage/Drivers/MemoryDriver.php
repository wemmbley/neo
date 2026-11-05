<?php

declare(strict_types=1);

namespace Neo\Storage\Drivers;

use Neo\Storage\Contracts\DriverContract;
use Neo\Storage\Contracts\ShouldProvide\ShouldProvideUrlContract;
use Neo\Storage\Enums\Visibility;
use Neo\Storage\Exceptions\FileAlreadyExistsException;
use Neo\Storage\Exceptions\FileNotFoundException;
use Neo\Storage\Exceptions\UnableToDeleteException;
use Neo\Storage\Exceptions\UnableToWriteException;
use Neo\Storage\Exceptions\UnsupportedOperationException;
use Neo\Storage\Domain\PathNormalizer;

/**
 * Драйвер в памяти: ничего не трогает на диске, идеален для тестов (fake()).
 * Пути проходят ту же нормализацию, что и в LocalDriver.
 *
 * Соглашение listContents(): директории возвращаются с завершающим "/".
 */
class MemoryDriver implements DriverContract, ShouldProvideUrlContract
{
    /** @var array<string, array{contents: string, visibility: Visibility, modified: int}> */
    private array $files = [];

    /** @var array<string, true> явные и неявные (предки файлов) директории */
    private array $dirs = [];

    public function __construct(private readonly string $url = '/storage/fake')
    {
    }

    public function read(string $path): string
    {
        return $this->file($path)['contents'];
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $this->read($path));
        rewind($stream);

        return $stream;
    }

    public function write(string $path, string $contents, array $options = []): void
    {
        $key = PathNormalizer::normalize($path);

        if (($options['overwrite'] ?? true) === false && isset($this->files[$key])) {
            throw new FileAlreadyExistsException("File [{$path}] already exists.");
        }

        if ($key === '' || $this->directoryExists($key)) {
            throw new UnableToWriteException("[{$path}] is a directory.");
        }

        $this->registerParents($key);
        $this->files[$key] = [
            'contents' => $contents,
            'visibility' => Visibility::resolve($options['visibility'] ?? null, Visibility::Private),
            'modified' => time(),
        ];
    }

    public function writeStream(string $path, $stream, array $options = []): void
    {
        $data = is_resource($stream) ? stream_get_contents($stream) : false;

        if ($data === false) {
            throw new UnableToWriteException('Invalid stream given.');
        }

        $this->write($path, $data, $options);
    }

    public function append(string $path, string $contents): void
    {
        $exists = $this->fileExists($path);
        $this->write($path, ($exists ? $this->read($path) : '') . $contents, ['visibility' => $exists ? $this->visibility($path) : null]);
    }

    public function prepend(string $path, string $contents): void
    {
        $exists = $this->fileExists($path);
        $this->write($path, $contents . ($exists ? $this->read($path) : ''), ['visibility' => $exists ? $this->visibility($path) : null]);
    }

    public function fileExists(string $path): bool
    {
        return isset($this->files[PathNormalizer::normalize($path)]);
    }

    public function directoryExists(string $path): bool
    {
        $key = PathNormalizer::normalize($path);

        return $key === '' || isset($this->dirs[$key]);
    }

    public function size(string $path): int
    {
        return strlen($this->read($path));
    }

    public function lastModified(string $path): int
    {
        return $this->file($path)['modified'];
    }

    public function mimeType(string $path): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($this->read($path));

        return $mime !== false ? $mime : 'application/octet-stream';
    }

    public function checksum(string $path, string $algo = 'md5'): string
    {
        if (!in_array($algo, hash_algos(), true)) {
            throw new UnsupportedOperationException("Unknown hash algorithm [{$algo}].");
        }

        return hash($algo, $this->read($path));
    }

    public function visibility(string $path): Visibility
    {
        return $this->file($path)['visibility'];
    }

    public function setVisibility(string $path, Visibility $visibility): void
    {
        $key = PathNormalizer::normalize($path);
        $this->file($path);
        $this->files[$key]['visibility'] = $visibility;
    }

    public function delete(string $path): void
    {
        $key = PathNormalizer::normalize($path);

        if ($this->directoryExists($key) && !isset($this->files[$key])) {
            throw new UnableToDeleteException("[{$path}] is a directory, use deleteDirectory().");
        }

        unset($this->files[$key]);
    }

    public function copy(string $from, string $to): void
    {
        $source = $this->file($from);
        $this->write($to, $source['contents'], ['overwrite' => false, 'visibility' => $source['visibility']]);
    }

    public function move(string $from, string $to): void
    {
        $this->copy($from, $to);
        $this->delete($from);
    }

    public function listContents(string $directory, bool $recursive = false): iterable
    {
        $prefix = PathNormalizer::normalize($directory);
        $prefix = $prefix === '' ? '' : $prefix . '/';
        $entries = [];

        $candidates = [
            ...array_map(static fn (string $p): array => [$p, false], array_keys($this->files)),
            ...array_map(static fn (string $p): array => [(string) $p, true], array_keys($this->dirs)),
        ];

        foreach ($candidates as [$path, $isDir]) {
            if ($prefix !== '' && !str_starts_with($path, $prefix)) {
                continue;
            }

            if ($path === rtrim($prefix, '/')) {
                continue;
            }

            if (!$recursive && str_contains(substr($path, strlen($prefix)), '/')) {
                continue;
            }

            $entries[] = $isDir ? $path . '/' : $path;
        }

        sort($entries);

        return $entries;
    }

    public function makeDirectory(string $path): void
    {
        $key = PathNormalizer::normalize($path);

        if (isset($this->files[$key])) {
            throw new UnableToWriteException("[{$path}] is a file.");
        }

        if ($key !== '') {
            $this->dirs[$key] = true;
            $this->registerParents($key);
        }
    }

    public function deleteDirectory(string $path): void
    {
        $key = PathNormalizer::normalize($path);

        if ($key === '') {
            throw new UnableToDeleteException('Refusing to delete the disk root.');
        }

        $prefix = $key . '/';
        $inside = static fn (string $p): bool => $p === $key || str_starts_with($p, $prefix);

        $this->files = array_filter($this->files, static fn (string $p): bool => !$inside($p), ARRAY_FILTER_USE_KEY);
        $this->dirs = array_filter($this->dirs, static fn ($v, $p): bool => !$inside((string) $p), ARRAY_FILTER_USE_BOTH);
    }

    public function url(string $path): string
    {
        $relative = PathNormalizer::normalize($path);

        return rtrim($this->url, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $relative)));
    }

    /** @return array{contents: string, visibility: Visibility, modified: int} */
    private function file(string $path): array
    {
        return $this->files[PathNormalizer::normalize($path)]
            ?? throw new FileNotFoundException("File [{$path}] does not exist.");
    }

    /** Регистрирует всех предков пути как директории. */
    private function registerParents(string $key): void
    {
        for ($dir = dirname($key); $dir !== '.' && $dir !== '/' && $dir !== ''; $dir = dirname($dir)) {
            $this->dirs[$dir] = true;
        }
    }
}