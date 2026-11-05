<?php

declare(strict_types=1);

namespace Neo\Logger\Drivers;

use Neo\Logger\Drivers\Basic\FileReportDriver;

class SingleReportDriver extends FileReportDriver
{
    protected function resolvePath(): string
    {
        return $this->config['path'];
    }
}