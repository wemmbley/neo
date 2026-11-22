<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Attributes\Relations;

use Attribute;

/** `#[MorphTo("imageable")]`: колонки imageable_type / imageable_id; доступ $image->imageable. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class MorphTo extends RelationAttribute
{
    public function __construct(string $name)
    {
        $this->related = null;
        $this->name = $name;
    }

    public function isMany(): bool
    {
        return false;
    }
}
