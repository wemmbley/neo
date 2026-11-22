<?php

declare(strict_types=1);

namespace Neo\Information\Logger\Drivers;

use Neo\Information\Logger\Drivers\Basic\FileReportDriver;

class DailyReportDriver extends FileReportDriver
{
    protected function resolvePath(): string
    {
        return $this->suffixedPath(date('Y-m-d'));
    }
}