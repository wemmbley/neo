<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Attributes\Relations;

use Attribute;

/** `#[MorphToMany(Tag::class, "taggable")]` */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class MorphToMany extends RelationAttribute
{
    public function __construct(
        ?string $related = null,
        public readonly ?string $morphName = null,
        ?string $name = null,
        public readonly ?string $table = null,
    ) {
        $this->related = $related;
        $this->name = $name;
    }

    public function isMany(): bool
    {
        return true;
    }
}
