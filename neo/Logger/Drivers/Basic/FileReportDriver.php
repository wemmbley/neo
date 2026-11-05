<?php

declare(strict_types=1);

namespace Neo\Logger\Drivers\Basic;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Базовый файловый драйвер: форматирует строку и атомарно дописывает её в файл.
 * Наследники определяют только имя файла (resolvePath).
 */
abstract class FileReportDriver extends BasicReportDriver
{
    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        if (!isset($config['path']) || !is_string($config['path']) || $config['path'] === '') {
            throw new InvalidArgumentException(static::class . " requires a non-empty 'path'.");
        }

        parent::__construct($config);
    }

    protected function write(string $level, string $message, array $context = []): void
    {
        $path = $this->resolvePath();
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create log directory: {$directory}");
        }

        $json = $context === []
            ? ''
            : json_encode(
                $this->normalize($context),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
            );

        $line = sprintf('[%s] %s: %s %s', date('Y-m-d H:i:s'), $level, $message, $json);
        $line = rtrim($line) . PHP_EOL;

        if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Unable to write log file: {$path}");
        }
    }

    /** Полный путь к файлу для текущей записи. */
    abstract protected function resolvePath(): string;

    /**
     * Добавляет суффикс к имени файла до расширения: app.log + "-2026-11-05" => app-2026-11-05.log.
     */
    protected function suffixedPath(string $suffix): string
    {
        $path = $this->config['path'];
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return dirname($path) . '/'
            . pathinfo($path, PATHINFO_FILENAME) . '-' . $suffix
            . ($extension !== '' ? ".{$extension}" : '');
    }

    /**
     * Приводит контекст к тому, что безопасно отдать в json_encode.
     */
    protected function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Throwable => [
                'class'   => $value::class,
                'message' => $value->getMessage(),
                'code'    => $value->getCode(),
                'file'    => $value->getFile(),
                'line'    => $value->getLine(),
                'trace'   => $value->getTraceAsString(),
            ],
            is_array($value)            => array_map($this->normalize(...), $value),
            $value instanceof \Stringable => (string) $value,
            is_object($value)           => $value::class,
            is_resource($value)         => 'resource(' . get_resource_type($value) . ')',
            default                     => $value,
        };
    }
}