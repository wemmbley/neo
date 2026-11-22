<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Relations\Concerns;

use Neo\Information\Persistence\ORM\Model;
use Neo\Information\Persistence\ORM\Support\Str;

/** Общая логика HasOne / HasMany: внешний ключ лежит в связанной таблице. */
trait MatchesByForeignKey
{
    private function foreignKey(): string
    {
        return $this->def->foreignKey ?? Str::snake(Str::basename($this->parent::class)) . '_id';
    }

    private function localValue(Model $parent): mixed
    {
        return $parent->getAttribute($this->def->localKey ?? $parent::metadata()->primaryProperty);
    }

    protected function addConstraints(): void
    {
        $this->whereParent($this->related->getTable() . '.' . $this->foreignKey(), $this->localValue($this->parent));
    }

    protected function addEagerConstraints(array $parents): bool
    {
        $keys = $this->keys($parents, fn (Model $p) => $this->localValue($p));

        if ($keys === []) {
            return false;
        }

        $this->query->whereIn($this->related->getTable() . '.' . $this->foreignKey(), $keys);

        return true;
    }

    protected function dictionaryKey(Model $result): mixed
    {
        return $result->getAttribute($this->foreignKey());
    }

    protected function parentKey(Model $parent): mixed
    {
        return $this->localValue($parent);
    }

    public function create(array $attributes = []): Model
    {
        $model = $this->related->newInstance($attributes)->forceFill([$this->foreignKey() => $this->localValue($this->parent)]);
        $model->save();

        return $model;
    }
}
