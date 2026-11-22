<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Attributes;

use Attribute;

/** Явный каст: int, float, string, bool, array, json, object, datetime, immutable_datetime, date, immutable_date, timestamp или enum/класс. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Cast
{
    public function __construct(public readonly string $type) {}
}
