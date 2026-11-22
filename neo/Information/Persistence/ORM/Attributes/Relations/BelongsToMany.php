<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Attributes\Relations;

use Attribute;

/** `#[BelongsToMany(User::class, pivotModel: OrganizationUser::class, pivotColumns: ["is_owner"])]` */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class BelongsToMany extends RelationAttribute
{
    public function __construct(
        ?string $related = null,
        ?string $name = null,
        public readonly ?string $table = null,
        public readonly ?string $foreignPivotKey = null,
        public readonly ?string $relatedPivotKey = null,
        public readonly ?string $pivotModel = null,
        public readonly array $pivotColumns = [],
    ) {
        $this->related = $related;
        $this->name = $name;
    }

    public function isMany(): bool
    {
        return true;
    }
}
