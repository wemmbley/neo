<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Attributes;

use Attribute;

/** Имя колонки и/или значение по умолчанию. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Column
{
    /** Маркер "значение по умолчанию не задано" (null тоже валидный default). */
    public const UNSET = "\0neo:unset";

    public function __construct(
        public readonly ?string $name = null,
        public readonly mixed $default = self::UNSET,
    ) {}

    public function hasDefault(): bool
    {
        return $this->default !== self::UNSET;
    }
}
