<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Attributes\Relations;

use Attribute;

/** `#[MorphOne(Image::class, "imageable")]` */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class MorphOne extends RelationAttribute
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
        return false;
    }
}
