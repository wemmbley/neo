<?php

declare(strict_types=1);

namespace Neo\Logger\Drivers;

use Neo\Logger\Drivers\Basic\FileReportDriver;

class DailyReportDriver extends FileReportDriver
{
    protected function resolvePath(): string
    {
        return $this->suffixedPath(date('Y-m-d'));
    }
}