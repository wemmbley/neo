<?php

declare(strict_types=1);

namespace Neo\Information\Logger\Drivers;

use Neo\Information\Logger\Drivers\Basic\BasicReportDriver;

class DisableReportingReportDriver extends BasicReportDriver
{
    protected function write(string $level, string $message, array $context = []): void
    {
        // Намеренно ничего не пишем.
    }
}