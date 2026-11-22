<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Relations;

use LogicException;
use Neo\Information\Persistence\ORM\Attributes\Relations as A;
use Neo\Information\Persistence\ORM\Attributes\Relations\RelationAttribute;
use Neo\Information\Persistence\ORM\Model;

final class RelationFactory
{
    public static function make(Model $parent, RelationAttribute $def): Relation
    {
        if ($def instanceof A\MorphTo) {
            return new MorphTo($parent, $def);
        }

        $related = $def->related ?? throw new LogicException('В атрибуте отношения не указан связанный класс.');
        $model = new $related();

        return match (true) {
            $def instanceof A\BelongsTo => new BelongsTo($parent, $model, $def),
            $def instanceof A\HasOne => new HasOne($parent, $model, $def),
            $def instanceof A\HasMany => new HasMany($parent, $model, $def),
            $def instanceof A\BelongsToMany => new BelongsToMany($parent, $model, $def),
            $def instanceof A\HasOneThrough => new HasOneThrough($parent, $model, $def),
            $def instanceof A\HasManyThrough => new HasManyThrough($parent, $model, $def),
            $def instanceof A\MorphOne => new MorphOne($parent, $model, $def),
            $def instanceof A\MorphMany => new MorphMany($parent, $model, $def),
            $def instanceof A\MorphToMany => new MorphToMany($parent, $model, $def),
            $def instanceof A\MorphedByMany => new MorphedByMany($parent, $model, $def),
            default => throw new LogicException('Неизвестный тип отношения: ' . $def::class),
        };
    }
}
