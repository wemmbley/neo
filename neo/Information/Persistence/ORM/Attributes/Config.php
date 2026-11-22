<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Attributes;

use Attribute;

/** Все настройки свойства одним атрибутом. Явные Cast/Column/Fillable/... сильнее. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Config
{
    /**
     * @param list<string|object>   $rules
     * @param array<string, string> $messages
     * @param class-string|null     $watch
     */
    public function __construct(
        public readonly ?bool $fillable = null,
        public readonly ?bool $hidden = null,
        public readonly ?bool $immutable = null,
        public readonly ?string $cast = null,
        public readonly ?string $column = null,
        public readonly mixed $default = Column::UNSET,
        public readonly array $rules = [],
        public readonly array $messages = [],
        public readonly ?string $watch = null,
    ) {}
}
