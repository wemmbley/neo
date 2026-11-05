<?php

declare(strict_types=1);

namespace Neo\Storage\Drivers;

use Neo\Storage\Contracts\DriverContract;
use Neo\Storage\Contracts\ShouldProvide\ShouldProvideLocalPathContract;
use Neo\Storage\Contracts\ShouldProvide\ShouldProvideUrlContract;
use Neo\Storage\Enums\Visibility;
use Neo\Storage\Exceptions\FileAlreadyExistsException;
use Neo\Storage\Exceptions\FileNotFoundException;
use Neo\Storage\Exceptions\PathTraversalException;
use Neo\Storage\Exceptions\UnableToDeleteException;
use Neo\Storage\Exceptions\UnableToReadException;
use Neo\Storage\Exceptions\UnableToWriteException;
use Neo\Storage\Exceptions\UnsupportedOperationException;
use Neo\Storage\Domain\PathNormalizer;

/**
 * Драйвер локальной файловой системы.
 *
 * Защита:
 *  1. PathNormalizer отклоняет `..`, нулевые байты, stream-обёртки.
 *  2. resolve() проверяет realpath ближайшего существующего предка, поэтому
 *     симлинк внутри root, указывающий наружу, не даёт выйти за пределы диска.
 *  3. Запись атомарная: временный файл в той же папке + rename().
 *
 * Соглашение listContents(): директории возвращаются с завершающим "/".
 */
class LocalDriver implements DriverContract, ShouldProvideUrlContract, ShouldProvideLocalPathContract
{
    /** Абсолютный realpath корня диска, без завершающего слеша. */
    private string $root;

    public function __construct(
        string $root,
        private readonly string $url = '/storage',
        private readonly Visibility $defaultVisibility = Visibility::Private,
    ) {
        $root = str_replace('\\', '/', $root);

        if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) {
            throw new UnableToWriteException('Unable to create the disk root directory.');
        }

        $real = realpath($root);

        if ($real === false) {
            throw new UnableToReadException('Unable to resolve the disk root directory.');
        }

        $this->root = rtrim(str_replace('\\', '/', $real), '/');
    }

    // ---------------------------------------------------------------- чтение

    public function read(string $path): string
    {
        $full = $this->existingFile($path);
        $data = @file_get_contents($full);

        return $data === false ? throw new UnableToReadException("Unable to read [{$path}].") : $data;
    }

    public function readStream(string $path)
    {
        $stream = @fopen($this->existingFile($path), 'rb');

        return $stream === false ? throw new UnableToReadException("Unable to open [{$path}].") : $stream;
    }

    // ---------------------------------------------------------------- запись

    public function write(string $path, string $contents, array $options = []): void
    {
        $this->store($path, static fn (string $tmp): bool => file_put_contents($tmp, $contents, LOCK_EX) !== false, $options);
    }

    public function writeStream(string $path, $stream, array $options = []): void
    {
        if (!is_resource($stream)) {
            throw new UnableToWriteException('Invalid stream given.');
        }

        $this->store($path, static function (string $tmp) use ($stream): bool {
            $target = @fopen($tmp, 'wb');

            if ($target === false) {
                return false;
            }

            $ok = stream_copy_to_stream($stream, $target) !== false;

            return fclose($target) && $ok;
        }, $options);
    }

    public function append(string $path, string $contents): void
    {
        $full = $this->resolve($path);

        if (is_dir($full)) {
            throw new UnableToWriteException("[{$path}] is a directory.");
        }

        $isNew = !file_exists($full);
        $this->ensureParent($full, $this->defaultVisibility);

        if (file_put_contents($full, $contents, FILE_APPEND | LOCK_EX) === false) {
            throw new UnableToWriteException("Unable to append to [{$path}].");
        }

        if ($isNew) {
            @chmod($full, $this->fileMode($this->defaultVisibility));
        }
    }

    public function prepend(string $path, string $contents): void
    {
        $exists = $this->fileExists($path);

        $this->write($path, $contents . ($exists ? $this->read($path) : ''), [
            'visibility' => $exists ? $this->visibility($path) : $this->defaultVisibility,
        ]);
    }

    // ---------------------------------------------------------------- проверки

    public function fileExists(string $path): bool
    {
        return is_file($this->resolve($path));
    }

    public function directoryExists(string $path): bool
    {
        return is_dir($this->resolve($path));
    }

    // ---------------------------------------------------------------- метаданные

    public function size(string $path): int
    {
        $size = @filesize($this->existingFile($path));

        return $size === false ? throw new UnableToReadException("Unable to get size of [{$path}].") : $size;
    }

    public function lastModified(string $path): int
    {
        $full = $this->existingFile($path);
        clearstatcache(true, $full);
        $time = @filemtime($full);

        return $time === false ? throw new UnableToReadException("Unable to get mtime of [{$path}].") : $time;
    }

    public function mimeType(string $path): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($this->existingFile($path));

        return $mime !== false ? $mime : 'application/octet-stream';
    }

    public function checksum(string $path, string $algo = 'md5'): string
    {
        if (!in_array($algo, hash_algos(), true)) {
            throw new UnsupportedOperationException("Unknown hash algorithm [{$algo}].");
        }

        $hash = @hash_file($algo, $this->existingFile($path));

        return $hash === false ? throw new UnableToReadException("Unable to hash [{$path}].") : $hash;
    }

    public function visibility(string $path): Visibility
    {
        $full = $this->resolve($path);

        if (!file_exists($full)) {
            throw new FileNotFoundException("[{$path}] does not exist.");
        }

        clearstatcache(true, $full);

        // Файл "публичный", если доступен на чтение всем остальным.
        return (fileperms($full) & 0004) !== 0 ? Visibility::Public : Visibility::Private;
    }

    public function setVisibility(string $path, Visibility $visibility): void
    {
        $full = $this->resolve($path);

        if (!file_exists($full)) {
            throw new FileNotFoundException("[{$path}] does not exist.");
        }

        $mode = is_dir($full) ? $this->dirMode($visibility) : $this->fileMode($visibility);

        if (!@chmod($full, $mode)) {
            throw new UnableToWriteException("Unable to change visibility of [{$path}].");
        }
    }

    // ---------------------------------------------------------------- операции

    /** Удаление идемпотентно: отсутствующий файл - не ошибка. */
    public function delete(string $path): void
    {
        $full = $this->resolve($path);

        if (!file_exists($full) && !is_link($full)) {
            return;
        }

        if (is_dir($full) && !is_link($full)) {
            throw new UnableToDeleteException("[{$path}] is a directory, use deleteDirectory().");
        }

        if (!@unlink($full)) {
            throw new UnableToDeleteException("Unable to delete [{$path}].");
        }
    }

    public function copy(string $from, string $to): void
    {
        $source = $this->existingFile($from);

        $this->store(
            $to,
            static fn (string $tmp): bool => copy($source, $tmp),
            ['overwrite' => false, 'visibility' => $this->visibility($from)],
        );
    }

    public function move(string $from, string $to): void
    {
        $source = $this->existingFile($from);
        $target = $this->resolve($to);

        if (file_exists($target)) {
            throw new FileAlreadyExistsException("File [{$to}] already exists.");
        }

        $this->ensureParent($target, $this->visibility($from));

        if (!@rename($source, $target)) {
            throw new UnableToWriteException("Unable to move [{$from}] to [{$to}].");
        }
    }

    // ---------------------------------------------------------------- директории

    public function listContents(string $directory, bool $recursive = false): iterable
    {
        $full = $this->resolve($directory);

        if (!is_dir($full)) {
            return [];
        }

        $flags = \FilesystemIterator::SKIP_DOTS;
        $iterator = $recursive
            ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, $flags), \RecursiveIteratorIterator::SELF_FIRST)
            : new \FilesystemIterator($full, $flags);

        $entries = [];

        foreach ($iterator as $info) {
            // Симлинки не показываем: они могут вести за пределы диска.
            if ($info->isLink() || preg_match('/^\.[0-9a-f]{16}\.tmp$/', $info->getFilename()) === 1) {
                continue;
            }

            $relative = ltrim(substr(str_replace('\\', '/', $info->getPathname()), strlen($this->root)), '/');
            $entries[] = $info->isDir() ? $relative . '/' : $relative;
        }

        sort($entries);

        return $entries;
    }

    public function makeDirectory(string $path): void
    {
        $full = $this->resolve($path);

        if (is_dir($full)) {
            return;
        }

        if (!@mkdir($full, $this->dirMode($this->defaultVisibility), true) && !is_dir($full)) {
            throw new UnableToWriteException("Unable to create directory [{$path}].");
        }
    }

    public function deleteDirectory(string $path): void
    {
        $full = $this->resolve($path);

        if (PathNormalizer::normalize($path) === '') {
            throw new UnableToDeleteException('Refusing to delete the disk root.');
        }

        if (is_link($full)) {
            if (!$this->unlinkAny($full)) { // симлинк удаляем сам, в цель не заходим
                throw new UnableToDeleteException("Unable to delete link [{$path}].");
            }

            return;
        }

        if (!is_dir($full)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $removed = $item->isLink()
                ? $this->unlinkAny($item->getPathname())
                : ($item->isFile()
                    ? @unlink($item->getPathname())
                    : @rmdir($item->getPathname()));

            if (!$removed) {
                throw new UnableToDeleteException("Unable to delete directory [{$path}].");
            }
        }

        if (!@rmdir($full)) {
            throw new UnableToDeleteException("Unable to delete directory [{$path}].");
        }
    }

    // ---------------------------------------------------------------- провайдеры

    public function url(string $path): string
    {
        $relative = PathNormalizer::normalize($path);
        $encoded = implode('/', array_map('rawurlencode', explode('/', $relative)));

        return rtrim($this->url, '/') . '/' . $encoded;
    }

    public function path(string $path): string
    {
        return $this->resolve($path);
    }

    // ---------------------------------------------------------------- внутреннее

    /**
     * Превращает относительный путь в абсолютный и гарантирует, что результат
     * остаётся внутри root (с учётом симлинков).
     *
     * @throws PathTraversalException
     */
    private function resolve(string $path): string
    {
        $relative = PathNormalizer::normalize($path);
        $full = $relative === '' ? $this->root : $this->root . '/' . $relative;

        // Файла может ещё не быть - проверяем ближайшего существующего предка.
        $probe = $full;

        while (!file_exists($probe) && !is_link($probe)) {
            $probe = dirname($probe);
        }

        $real = realpath($probe); // для битого симлинка вернёт false - тоже отказ

        if ($real === false) {
            throw new PathTraversalException("Path [{$path}] cannot be resolved safely.");
        }

        $real = str_replace('\\', '/', $real);

        if ($real !== $this->root && !str_starts_with($real, $this->root . '/')) {
            throw new PathTraversalException("Path [{$path}] resolves outside of the disk root.");
        }

        return $full;
    }

    /** Путь к существующему обычному файлу или FileNotFoundException. */
    private function existingFile(string $path): string
    {
        $full = $this->resolve($path);

        return is_file($full) ? $full : throw new FileNotFoundException("File [{$path}] does not exist.");
    }

    /**
     * Атомарная запись: пишем во временный файл рядом и переименовываем.
     * Читатель никогда не увидит наполовину записанный файл.
     *
     * @param \Closure(string): bool $fill наполняет временный файл
     */
    private function store(string $path, \Closure $fill, array $options): void
    {
        $full = $this->resolve($path);

        if (($options['overwrite'] ?? true) === false && file_exists($full)) {
            throw new FileAlreadyExistsException("File [{$path}] already exists.");
        }

        if (is_dir($full)) {
            throw new UnableToWriteException("[{$path}] is a directory.");
        }

        $visibility = Visibility::resolve($options['visibility'] ?? null, $this->defaultVisibility);
        $this->ensureParent($full, $visibility);

        $tmp = dirname($full) . '/.' . bin2hex(random_bytes(8)) . '.tmp';
        $done = false;

        try {
            $done = $fill($tmp)
                && @chmod($tmp, $this->fileMode($visibility))
                && @rename($tmp, $full);
        } finally {
            if (!$done) {
                @unlink($tmp);
            }
        }

        if (!$done) {
            throw new UnableToWriteException("Unable to write [{$path}].");
        }
    }

    private function ensureParent(string $full, Visibility $visibility): void
    {
        $dir = dirname($full);

        if (!is_dir($dir) && !@mkdir($dir, $this->dirMode($visibility), true) && !is_dir($dir)) {
            throw new UnableToWriteException('Unable to create the parent directory.');
        }
    }

    private function fileMode(Visibility $visibility): int
    {
        return $visibility === Visibility::Public ? 0644 : 0600;
    }

    private function dirMode(Visibility $visibility): int
    {
        return $visibility === Visibility::Public ? 0755 : 0700;
    }

    /** Удаляет симлинк (на файл или папку), цель не затрагивается. */
    private function unlinkAny(string $path): bool
    {
        // Windows: симлинк на папку удаляется только через rmdir
        return @unlink($path) || (is_dir($path) && @rmdir($path));
    }
}