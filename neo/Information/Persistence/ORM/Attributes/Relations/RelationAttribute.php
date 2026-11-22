<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Attributes\Relations;

/**
 * Общий предок атрибутов отношений. $name: имя доступа ($model->name); null: выводится из related.
 */
abstract class RelationAttribute
{
    /** @var class-string|null */
    public ?string $related = null;

    public ?string $name = null;

    abstract public function isMany(): bool;
}
