<?php

declare(strict_types=1);

namespace Neo\Storage\Domain;

use Neo\Storage\Exceptions\PathTraversalException;

/**
 * Приводит пользовательский путь к безопасному относительному виду.
 *
 * Политика намеренно жёсткая: мы НЕ "разруливаем" `..`, а отклоняем путь целиком.
 * Легитимному коду `..` в путях стореджа не нужен.
 */
final class PathNormalizer
{
    /**
     * Нормализует путь: "/a//b/./c" => "a/b/c".
     * Пустая строка означает корень диска.
     *
     * @throws PathTraversalException
     */
    public static function normalize(string $path): string
    {
        // Нулевые байты и управляющие символы - классика обхода проверок.
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new PathTraversalException('Path contains control characters.');
        }

        $path = str_replace('\\', '/', $path);

        // Обёртки потоков (php://, file://, phar://) и буквы дисков Windows (C:).
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $path) === 1 || preg_match('#^[a-zA-Z]:#', $path) === 1) {
            throw new PathTraversalException('Stream wrappers and drive letters are not allowed.');
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw new PathTraversalException('Parent directory references are not allowed.');
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * Склеивает части пути и нормализует результат.
     *
     * @throws PathTraversalException
     */
    public static function join(string ...$parts): string
    {
        return self::normalize(implode('/', $parts));
    }

    /**
     * Проверяет, что имя - это ровно один сегмент (без папок).
     * Нужно там, где имя файла приходит отдельно от директории.
     *
     * @throws PathTraversalException
     */
    public static function filename(string $name): string
    {
        $clean = self::normalize($name);

        if ($clean === '' || $clean !== $name || str_contains($clean, '/')) {
            throw new PathTraversalException('File name must be a single path segment.');
        }

        return $clean;
    }

    /** Удаляет симлинк (файла или папки), не трогая цель. */
    public static function removeLink(string $path): bool
    {
        if (@unlink($path)) {
            return true;
        }

        // Windows: симлинк на папку удаляется только через rmdir
        return is_dir($path) && @rmdir($path);
    }
}