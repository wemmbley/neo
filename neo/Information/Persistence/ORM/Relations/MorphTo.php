<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Relations;

use Closure;
use Neo\Information\Persistence\ORM\Attributes\Relations\MorphTo as Def;
use Neo\Information\Persistence\ORM\EagerLoader;
use Neo\Information\Persistence\ORM\Model;

/**
 * Тип связанной модели лежит в данных ({name}_type), поэтому запрос строится по месту.
 * В {name}_type допускаются только наследники Model: произвольный класс из БД не создаётся.
 */
class MorphTo extends OneRelation
{
    public function __construct(Model $parent, private readonly Def $def)
    {
        $class = $parent->getAttribute($def->name . '_type');

        parent::__construct($parent, self::isModelClass($class) ? new $class() : $parent);
    }

    private static function isModelClass(mixed $class): bool
    {
        return is_string($class) && is_subclass_of($class, Model::class);
    }

    protected function addConstraints(): void
    {
        $this->whereParent($this->related->getQualifiedKeyName(), $this->parent->getAttribute($this->def->name . '_id'));
    }

    public function getResults(): mixed
    {
        return self::isModelClass($this->parent->getAttribute($this->def->name . '_type'))
            ? parent::getResults()
            : null;
    }

    /** По одному запросу на каждый встретившийся тип. */
    public function eagerLoad(array $parents, string $name, ?Closure $constraint, array $nested): void
    {
        $morph = $this->def->name;
        $ids = [];

        foreach ($parents as $parent) {
            $type = $parent->getAttribute($morph . '_type');
            $id = $parent->getAttribute($morph . '_id');

            if (self::isModelClass($type) && $id !== null) {
                $ids[$type][(string) $id] = $id;
            }
        }

        $found = [];

        foreach ($ids as $class => $keys) {
            $query = $class::query()->whereIn((new $class())->getQualifiedKeyName(), array_values($keys));
            $constraint && $constraint($query);

            $results = $query->getModels();
            EagerLoader::load($results, $nested);

            foreach ($results as $result) {
                $found[$class][$result->getKey()] = $result;
            }
        }

        foreach ($parents as $parent) {
            $type = $parent->getAttribute($morph . '_type');
            $id = $parent->getAttribute($morph . '_id');

            $parent->setRelation($name, is_string($type) && $id !== null ? ($found[$type][$id] ?? null) : null);
        }
    }
}
