<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Relations;

use Neo\Information\Persistence\ORM\Attributes\Relations\MorphOne as Def;
use Neo\Information\Persistence\ORM\Model;
use Neo\Information\Persistence\ORM\Relations\Concerns\MatchesByMorph;

class MorphOne extends OneRelation
{
    use MatchesByMorph;

    public function __construct(Model $parent, Model $related, private readonly Def $def)
    {
        parent::__construct($parent, $related);
    }
}
