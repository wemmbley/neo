<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Relations;

use Neo\Information\Persistence\ORM\Attributes\Relations\MorphedByMany as InverseDef;
use Neo\Information\Persistence\ORM\Attributes\Relations\MorphToMany as Def;
use Neo\Information\Persistence\ORM\Model;
use Neo\Information\Persistence\ORM\Support\Str;

/**
 * Pivot `taggables`: {morph}_id, {morph}_type и ключ второй стороны.
 * MorphToMany: родитель полиморфный (Post -> tags); MorphedByMany: наоборот (Tag -> posts).
 */
class MorphToMany extends ManyRelation
{
    protected const INVERSE = false;

    public function __construct(Model $parent, Model $related, protected readonly Def|InverseDef $def)
    {
        parent::__construct($parent, $related);
    }

    private function table(): string
    {
        return $this->def->table ?? Str::plural((string) $this->def->morphName);
    }

    /** Колонка pivot с ключом родителя. */
    private function linkColumn(): string
    {
        return static::INVERSE ? Str::snake(Str::basename($this->parent::class)) . '_id' : $this->def->morphName . '_id';
    }

    /** Колонка pivot с ключом связанной модели. */
    private function relatedColumn(): string
    {
        return static::INVERSE ? $this->def->morphName . '_id' : Str::snake(Str::basename($this->related::class)) . '_id';
    }

    /** Класс, который хранится в {morph}_type. */
    private function morphClass(): string
    {
        return static::INVERSE ? $this->related::class : $this->parent::class;
    }

    protected function baseQuery(): void
    {
        $pivot = $this->table();
        $rt = $this->related->getTable();

        $this->query->join($pivot, $rt . '.' . $this->related->getKeyName(), '=', $pivot . '.' . $this->relatedColumn())
            ->where($pivot . '.' . $this->def->morphName . '_type', '=', $this->morphClass())
            ->select($rt . '.*', $pivot . '.' . $this->linkColumn() . ' as pivot_neo_key');
    }

    protected function addConstraints(): void
    {
        $this->whereParent($this->table() . '.' . $this->linkColumn(), $this->parent->getKey());
    }

    protected function addEagerConstraints(array $parents): bool
    {
        $keys = $this->keys($parents, fn (Model $p) => $p->getKey());

        if ($keys === []) {
            return false;
        }

        $this->query->whereIn($this->table() . '.' . $this->linkColumn(), $keys);

        return true;
    }

    protected function dictionaryKey(Model $result): mixed
    {
        return $result->getAttribute('pivot_neo_key');
    }

    protected function parentKey(Model $parent): mixed
    {
        return $parent->getKey();
    }

    protected function cleanup(Model $result): void
    {
        unset($result->pivot_neo_key);
    }

    /** @param int|string|list<int|string>|Model $ids */
    public function attach(mixed $ids, array $extra = []): void
    {
        foreach ((array) ($ids instanceof Model ? $ids->getKey() : $ids) as $id) {
            $this->parent->getConnection()->table($this->table())->insert([
                $this->linkColumn() => $this->parent->getKey(),
                $this->relatedColumn() => $id,
                $this->def->morphName . '_type' => $this->morphClass(),
                ...$extra,
            ]);
        }
    }
}
