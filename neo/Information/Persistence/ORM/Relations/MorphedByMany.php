<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Relations;

use Neo\Information\Persistence\ORM\Attributes\Relations\MorphedByMany as Def;
use Neo\Information\Persistence\ORM\Model;

class MorphedByMany extends MorphToMany
{
    protected const INVERSE = true;

    public function __construct(Model $parent, Model $related, Def $def)
    {
        parent::__construct($parent, $related, $def);
    }
}
