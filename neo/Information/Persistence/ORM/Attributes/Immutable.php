<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Attributes;

use Attribute;

/** Свойство нельзя менять после сохранения модели. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Immutable
{
    public function __construct(public readonly bool $value = true) {}
}
