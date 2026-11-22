<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Attributes;

use Attribute;

/** Настройки модели: соединение, таблица, timestamps. */
#[Attribute(Attribute::TARGET_CLASS)]
final class DB
{
    public function __construct(
        public readonly ?string $connection = null,
        public readonly ?string $table = null,
        public readonly ?bool $timestamps = null,
    ) {}
}
