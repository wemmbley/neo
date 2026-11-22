<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Attributes;

use Attribute;

/** Первичный ключ модели. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class PrimaryKey
{
    public function __construct(public readonly bool $autoIncrement = true) {}
}
