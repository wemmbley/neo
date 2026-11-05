<?php

declare(strict_types=1);

namespace Neo\Logger\Drivers;

use Neo\Logger\Drivers\Basic\BasicReportDriver;
use Neo\Logger\Drivers\Basic\ReportDriverContract;

/**
 * Рассылает запись во все вложенные драйверы.
 * Если один драйвер упал, остальные всё равно получат запись,
 * а первая ошибка пробрасывается после обхода.
 */
class StackReportDriver extends BasicReportDriver
{
    /**
     * @param array<string, mixed>   $config
     * @param ReportDriverContract[] $drivers
     */
    public function __construct(array $config = [], protected array $drivers = [])
    {
        parent::__construct($config);
    }

    protected function write(string $level, string $message, array $context = []): void
    {
        $failure = null;

        foreach ($this->drivers as $driver) {
            try {
                $driver->log($level, $message, $context);
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }
}