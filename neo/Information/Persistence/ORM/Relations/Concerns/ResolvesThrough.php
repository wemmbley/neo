<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Relations\Concerns;

use Neo\Information\Persistence\ORM\Model;
use Neo\Information\Persistence\ORM\Support\Str;

/**
 * Общая логика HasOneThrough / HasManyThrough:
 * parent <- through.first_key, through.second_local_key <- related.second_key.
 */
trait ResolvesThrough
{
    private ?Model $throughModel = null;

    private function through(): Model
    {
        return $this->throughModel ??= new ($this->def->through)();
    }

    private function firstKey(): string
    {
        return $this->def->firstKey ?? Str::snake(Str::basename($this->parent::class)) . '_id';
    }

    private function localValue(Model $parent): mixed
    {
        return $parent->getAttribute($this->def->localKey ?? $parent::metadata()->primaryProperty);
    }

    protected function baseQuery(): void
    {
        $tt = $this->through()->getTable();
        $rt = $this->related->getTable();
        $secondKey = $this->def->secondKey ?? Str::snake(Str::basename($this->through()::class)) . '_id';
        $secondLocal = $this->def->secondLocalKey ?? $this->through()->getKeyName();

        $this->query->join($tt, $tt . '.' . $secondLocal, '=', $rt . '.' . $secondKey)
            ->select($rt . '.*', $tt . '.' . $this->firstKey() . ' as neo_through_key');
    }

    protected function addConstraints(): void
    {
        $this->whereParent($this->through()->getTable() . '.' . $this->firstKey(), $this->localValue($this->parent));
    }

    protected function addEagerConstraints(array $parents): bool
    {
        $keys = $this->keys($parents, fn (Model $p) => $this->localValue($p));

        if ($keys === []) {
            return false;
        }

        $this->query->whereIn($this->through()->getTable() . '.' . $this->firstKey(), $keys);

        return true;
    }

    protected function dictionaryKey(Model $result): mixed
    {
        return $result->getAttribute('neo_through_key');
    }

    protected function parentKey(Model $parent): mixed
    {
        return $this->localValue($parent);
    }

    protected function cleanup(Model $result): void
    {
        unset($result->neo_through_key);
    }
}
