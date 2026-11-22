<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Relations;

abstract class OneRelation extends Relation
{
    public function isMany(): bool
    {
        return false;
    }

    public function getResults(): mixed
    {
        return $this->query->first();
    }
}
