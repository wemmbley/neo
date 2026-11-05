<?php

declare(strict_types=1);

namespace Neo\Logger\Drivers\Basic;

use InvalidArgumentException;
use Stringable;

/**
 * Базовый драйвер: проверяет уровни, фильтрует по минимальному уровню
 * и подставляет {placeholders} из контекста в сообщение.
 */
abstract class BasicReportDriver implements ReportDriverContract
{
    protected const LEVELS = [
        'emergency' => 0,
        'alert'     => 1,
        'critical'  => 2,
        'error'     => 3,
        'warning'   => 4,
        'notice'    => 5,
        'info'      => 6,
        'debug'     => 7,
    ];

    /** @param array<string, mixed> $config */
    public function __construct(protected array $config = [])
    {
        $minimum = $this->config['level'] ?? 'debug';

        if (!is_string($minimum) || !isset(self::LEVELS[$minimum])) {
            throw new InvalidArgumentException('Invalid minimum log level: ' . json_encode($minimum));
        }
    }

    public function log(string $level, string $message, array $context = []): void
    {
        if (!isset(self::LEVELS[$level])) {
            throw new InvalidArgumentException("Invalid log level: {$level}");
        }

        if (self::LEVELS[$level] > self::LEVELS[$this->config['level'] ?? 'debug']) {
            return;
        }

        $this->write($level, $this->interpolate($message, $context), $context);
    }

    /**
     * Записывает уже отфильтрованное сообщение.
     *
     * @param array<string, mixed> $context
     */
    abstract protected function write(string $level, string $message, array $context = []): void;

    /**
     * Заменяет {key} в сообщении значениями из контекста (PSR-3 стиль).
     * Подставляются только скаляры и Stringable.
     *
     * @param array<string, mixed> $context
     */
    protected function interpolate(string $message, array $context): string
    {
        if ($context === [] || !str_contains($message, '{')) {
            return $message;
        }

        $replace = [];

        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof Stringable) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replace);
    }

    public function emergency(string $message, array $context = []): void { $this->log('emergency', $message, $context); }
    public function alert(string $message, array $context = []): void     { $this->log('alert', $message, $context); }
    public function critical(string $message, array $context = []): void  { $this->log('critical', $message, $context); }
    public function error(string $message, array $context = []): void     { $this->log('error', $message, $context); }
    public function warning(string $message, array $context = []): void   { $this->log('warning', $message, $context); }
    public function notice(string $message, array $context = []): void    { $this->log('notice', $message, $context); }
    public function info(string $message, array $context = []): void      { $this->log('info', $message, $context); }
    public function debug(string $message, array $context = []): void     { $this->log('debug', $message, $context); }
}