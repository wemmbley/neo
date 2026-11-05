<?php

declare(strict_types=1);

namespace Neo\Logger\Channels;

use Throwable;

abstract class BasicReportChannel implements ReportChannelContract
{
    /** @param array<string, mixed> $config */
    public function __construct(protected array $config = []) {}

    abstract public function send(Throwable $exception): void;
}