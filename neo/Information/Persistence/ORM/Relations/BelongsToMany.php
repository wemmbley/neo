<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Relations;

use Neo\Information\Persistence\ORM\Attributes\Relations\BelongsToMany as Def;
use Neo\Information\Persistence\ORM\Model;
use Neo\Information\Structures\Str;

class BelongsToMany extends ManyRelation
{
    public function __construct(Model $parent, Model $related, private readonly Def $def)
    {
        parent::__construct($parent, $related);
    }

    public function pivotTable(): string
    {
        if ($this->def->table !== null) {
            return $this->def->table;
        }

        if ($this->def->pivotModel !== null) {
            return (new ($this->def->pivotModel)())->getTable();
        }

        $names = [Str::snake(Str::basename($this->parent::class)), Str::snake(Str::basename($this->related::class))];
        sort($names);

        return implode('_', $names);
    }

    private function foreignPivotKey(): string
    {
        return $this->def->foreignPivotKey ?? Str::snake(Str::basename($this->parent::class)) . '_id';
    }

    private function relatedPivotKey(): string
    {
        return $this->def->relatedPivotKey ?? Str::snake(Str::basename($this->related::class)) . '_id';
    }

    /** @return list<string> */
    private function pivotColumns(): array
    {
        return [$this->foreignPivotKey(), $this->relatedPivotKey(), ...$this->def->pivotColumns];
    }

    public function linkage(): array
    {
        return [
            'type' => 'pivot',
            'parent' => $this->parent->getKeyName(),
            'related' => $this->related->getKeyName(),
            'pivot' => $this->pivotTable(),
            'pivot_parent' => $this->foreignPivotKey(),
            'pivot_related' => $this->relatedPivotKey(),
        ];
    }

    protected function baseQuery(): void
    {
        $pivot = $this->pivotTable();
        $rt = $this->related->getTable();

        $select = [$rt . '.*'];
        foreach ($this->pivotColumns() as $column) {
            $select[] = $pivot . '.' . $column . ' as pivot_' . $column;
        }

        $this->query->join($pivot, $rt . '.' . $this->related->getKeyName(), '=', $pivot . '.' . $this->relatedPivotKey())
            ->select(...$select);
    }

    protected function addConstraints(): void
    {
        $this->whereParent($this->pivotTable() . '.' . $this->foreignPivotKey(), $this->parent->getKey());
    }

    protected function addEagerConstraints(array $parents): bool
    {
        $keys = $this->keys($parents, fn (Model $p) => $p->getKey());

        if ($keys === []) {
            return false;
        }

        $this->query->whereIn($this->pivotTable() . '.' . $this->foreignPivotKey(), $keys);

        return true;
    }

    /** Модели с pivot-колонками в `$model->pivot` (массив). */
    protected function models(): array
    {
        $models = $this->query->getModels();

        foreach ($models as $model) {
            $pivot = [];

            foreach ($this->pivotColumns() as $column) {
                $pivot[$column] = $model->getAttribute('pivot_' . $column);
                unset($model->{'pivot_' . $column});
            }

            $model->setRelation('pivot', $pivot);
        }

        return $models;
    }

    protected function dictionaryKey(Model $result): mixed
    {
        return $result->getRelation('pivot')[$this->foreignPivotKey()] ?? null;
    }

    protected function parentKey(Model $parent): mixed
    {
        return $parent->getKey();
    }

    /** @param int|string|list<int|string>|Model $ids */
    public function attach(mixed $ids, array $extra = []): void
    {
        foreach ((array) ($ids instanceof Model ? $ids->getKey() : $ids) as $id) {
            $this->parent->getConnection()->table($this->pivotTable())->insert([
                $this->foreignPivotKey() => $this->parent->getKey(),
                $this->relatedPivotKey() => $id,
                ...$extra,
            ]);
        }
    }

    public function detach(mixed $ids = null): int
    {
        $q = $this->parent->getConnection()->table($this->pivotTable())
            ->where($this->foreignPivotKey(), '=', $this->parent->getKey());

        if ($ids !== null) {
            $q->whereIn($this->relatedPivotKey(), (array) ($ids instanceof Model ? $ids->getKey() : $ids));
        }

        return $q->delete();
    }

    /** Привести набор связей к точному списку id. */
    public function sync(array $ids): void
    {
        $this->detach();
        $this->attach($ids);
    }
}
