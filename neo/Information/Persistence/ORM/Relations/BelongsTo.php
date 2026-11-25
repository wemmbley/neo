<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Relations;

use Neo\Information\Persistence\ORM\Attributes\Relations\BelongsTo as Def;
use Neo\Information\Persistence\ORM\Model;
use Neo\Information\Structures\Str;

class BelongsTo extends OneRelation
{
    public function __construct(Model $parent, Model $related, private readonly Def $def)
    {
        parent::__construct($parent, $related);
    }

    private function foreignKey(): string
    {
        return $this->def->foreignKey ?? Str::snake((string) $this->def->name) . '_id';
    }

    private function ownerKey(): string
    {
        return $this->def->ownerKey ?? $this->related->getKeyName();
    }

    public function linkage(): array
    {
        return ['type' => 'belongs_to', 'parent' => $this->foreignKey(), 'related' => $this->ownerKey()];
    }

    protected function addConstraints(): void
    {
        $this->whereParent(
            $this->related->getTable() . '.' . $this->ownerKey(),
            $this->parent->getAttribute($this->foreignKey()),
        );
    }

    protected function addEagerConstraints(array $parents): bool
    {
        $keys = $this->keys($parents, fn (Model $p) => $p->getAttribute($this->foreignKey()));

        if ($keys === []) {
            return false;
        }

        $this->query->whereIn($this->related->getTable() . '.' . $this->ownerKey(), $keys);

        return true;
    }

    protected function dictionaryKey(Model $result): mixed
    {
        return $result->getAttribute($this->ownerKey());
    }

    protected function parentKey(Model $parent): mixed
    {
        return $parent->getAttribute($this->foreignKey());
    }

    /** Привязать владельца: `$comment->post()->associate($post)`. */
    public function associate(Model $owner): Model
    {
        $this->parent->setAttribute($this->foreignKey(), $owner->getAttribute($this->ownerKey()));
        $this->parent->setRelation((string) $this->def->name, $owner);

        return $this->parent;
    }
}
