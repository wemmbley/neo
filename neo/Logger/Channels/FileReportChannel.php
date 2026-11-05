<?php

declare(strict_types=1);

namespace Neo\Logger\Channels;

use Neo\Logger\Drivers\Basic\ReportDriverContract;
use Throwable;

/**
 * Пишет исключение в лог через указанный драйвер.
 * Конфиг: ['channel' => 'file', 'driver' => 'daily', 'level' => 'error'].
 */
class FileReportChannel extends BasicReportChannel
{
    /** @param array<string, mixed> $config */
    public function __construct(
        protected ReportDriverContract $driver,
        array $config = []
    ) {
        parent::__construct($config);
    }

    public function send(Throwable $exception): void
    {
        $level = $this->config['level'] ?? 'error';

        // Подробности (класс, файл, строка, трейс) добавит нормализатор драйвера.
        $this->driver->log($level, $exception->getMessage(), ['exception' => $exception]);
    }
}