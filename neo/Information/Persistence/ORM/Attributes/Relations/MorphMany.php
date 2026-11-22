<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Attributes\Relations;

use Attribute;

/** `#[MorphMany(Image::class, "imageable")]` */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class MorphMany extends RelationAttribute
{
    public function __construct(
        ?string $related = null,
        public readonly ?string $morphName = null,
        ?string $name = null,
    ) {
        $this->related = $related;
        $this->name = $name;
    }

    public function isMany(): bool
    {
        return true;
    }
}
