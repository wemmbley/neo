<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Attributes;

use Attribute;

/** Свойство скрыто из toArray() / toJson(). */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Hidden
{
    public function __construct(public readonly bool $value = true) {}
}
