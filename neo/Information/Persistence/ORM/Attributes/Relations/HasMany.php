<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Attributes\Relations;

use Attribute;

/** `#[HasMany(Post::class)]` */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class HasMany extends RelationAttribute
{
    public function __construct(
        ?string $related = null,
        ?string $name = null,
        public readonly ?string $foreignKey = null,
        public readonly ?string $localKey = null,
    ) {
        $this->related = $related;
        $this->name = $name;
    }

    public function isMany(): bool
    {
        return true;
    }
}
