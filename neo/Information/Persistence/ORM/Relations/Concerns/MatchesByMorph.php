<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Relations\Concerns;

use Neo\Information\Persistence\ORM\Model;

/** Общая логика MorphOne / MorphMany: у связанной модели есть {name}_type и {name}_id. */
trait MatchesByMorph
{
    private function morph(): string
    {
        return (string) $this->def->morphName;
    }

    protected function baseQuery(): void
    {
        $this->query->where($this->related->getTable() . '.' . $this->morph() . '_type', '=', $this->parent::class);
    }

    protected function addConstraints(): void
    {
        $this->whereParent($this->related->getTable() . '.' . $this->morph() . '_id', $this->parent->getKey());
    }

    protected function addEagerConstraints(array $parents): bool
    {
        $keys = $this->keys($parents, fn (Model $p) => $p->getKey());

        if ($keys === []) {
            return false;
        }

        $this->query->whereIn($this->related->getTable() . '.' . $this->morph() . '_id', $keys);

        return true;
    }

    protected function dictionaryKey(Model $result): mixed
    {
        return $result->getAttribute($this->morph() . '_id');
    }

    protected function parentKey(Model $parent): mixed
    {
        return $parent->getKey();
    }

    public function create(array $attributes = []): Model
    {
        $model = $this->related->newInstance($attributes)->forceFill([
            $this->morph() . '_type' => $this->parent::class,
            $this->morph() . '_id' => $this->parent->getKey(),
        ]);
        $model->save();

        return $model;
    }
}
