<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Attributes\Relations;

use Attribute;

/** `#[HasOneThrough(Manufacturer::class, Computer::class)]` */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class HasOneThrough extends RelationAttribute
{
    public function __construct(
        ?string $related = null,
        public readonly ?string $through = null,
        ?string $name = null,
        public readonly ?string $firstKey = null,
        public readonly ?string $secondKey = null,
        public readonly ?string $localKey = null,
        public readonly ?string $secondLocalKey = null,
    ) {
        $this->related = $related;
        $this->name = $name;
    }

    public function isMany(): bool
    {
        return false;
    }
}
