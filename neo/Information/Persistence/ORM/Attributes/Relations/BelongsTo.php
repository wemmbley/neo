<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Attributes\Relations;

use Attribute;

/** Комментарий принадлежит посту: `#[BelongsTo(Post::class)]`; `#[BelongsTo(User::class, "author", "custom_id", "id")]`. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class BelongsTo extends RelationAttribute
{
    public function __construct(
        ?string $related = null,
        ?string $name = null,
        public readonly ?string $foreignKey = null,
        public readonly ?string $ownerKey = null,
    ) {
        $this->related = $related;
        $this->name = $name;
    }

    public function isMany(): bool
    {
        return false;
    }
}
