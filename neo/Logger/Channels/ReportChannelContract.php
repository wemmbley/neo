<?php

namespace Neo\Logger\Channels;

use Throwable;

interface ReportChannelContract
{
    public function send(Throwable $exception): void;
}