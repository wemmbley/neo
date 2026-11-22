<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Attributes;

use Attribute;

/** Свойство можно массово заполнять (по умолчанию так и есть). */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Fillable
{
    public function __construct(public readonly bool $value = true) {}
}
