<?php

declare(strict_types=1);

namespace Neo\Information\Logger\Drivers;

use Neo\Information\Logger\Drivers\Basic\FileReportDriver;

class SingleReportDriver extends FileReportDriver
{
    protected function resolvePath(): string
    {
        return $this->config['path'];
    }
}