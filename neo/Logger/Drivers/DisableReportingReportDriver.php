<?php

declare(strict_types=1);

namespace Neo\Logger\Drivers;

use Neo\Logger\Drivers\Basic\BasicReportDriver;

class DisableReportingReportDriver extends BasicReportDriver
{
    protected function write(string $level, string $message, array $context = []): void
    {
        // Намеренно ничего не пишем.
    }
}