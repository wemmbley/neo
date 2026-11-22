<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Relations;

abstract class ManyRelation extends Relation
{
    public function isMany(): bool
    {
        return true;
    }

    public function getResults(): mixed
    {
        return $this->get();
    }

    /** Коллекция связанных моделей. */
    public function get(): mixed
    {
        return $this->related->newCollection($this->models());
    }
}
