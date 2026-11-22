<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM;

use Closure;
use Neo\Information\Persistence\ORM\Relations\Relation;

/**
 * Пакетная загрузка отношений: на каждое отношение (и каждый уровень вложенности) один запрос
 * независимо от числа моделей. Это и есть защита от N+1.
 *
 * Формат отношений: ['comments', 'comments.author', 'tags' => fn (Builder $q) => $q->where(...)].
 */
final class EagerLoader
{
    /**
     * @param list<Model>                         $models
     * @param array<int|string, string|Closure|null> $relations
     */
    public static function load(array $models, array $relations): void
    {
        if ($models === [] || $relations === []) {
            return;
        }

        $tree = self::tree($relations);

        # Модели могут быть разных классов (например, результат MorphTo): грузим по группам.
        foreach (self::groupByClass($models) as $group) {
            foreach ($tree as $name => [$constraint, $nested]) {
                Relation::noConstraints(fn () => $group[0]->relation($name))
                    ->eagerLoad($group, $name, $constraint, $nested);
            }
        }
    }

    /** Загрузить только то, чего ещё нет хотя бы у одной модели. */
    public static function loadMissing(array $models, array $relations): void
    {
        $missing = [];

        foreach ($relations as $key => $value) {
            $path = is_int($key) ? $value : $key;
            $first = explode('.', (string) $path, 2)[0];

            foreach ($models as $model) {
                if (!$model->relationLoaded($first)) {
                    is_int($key) ? $missing[] = $value : $missing[$key] = $value;
                    break;
                }
            }
        }

        self::load($models, $missing);
    }

    /**
     * Привести аргументы with()/load() к единому виду.
     *
     * @param  array<int, string|array> $arguments
     * @return array<int|string, string|Closure|null>
     */
    public static function normalize(array $arguments): array
    {
        $out = [];

        foreach ($arguments as $argument) {
            foreach ((array) $argument as $key => $value) {
                is_int($key) ? $out[] = $value : $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @return array<string, array{0: Closure|null, 1: array<string, Closure|null>}> */
    private static function tree(array $relations): array
    {
        $tree = [];

        foreach ($relations as $key => $value) {
            [$path, $constraint] = is_int($key) ? [$value, null] : [$key, $value];
            [$name, $rest] = array_pad(explode('.', (string) $path, 2), 2, null);

            $tree[$name] ??= [null, []];

            if ($rest === null) {
                $tree[$name][0] = $constraint;
            } else {
                $tree[$name][1][$rest] = $constraint;
            }
        }

        return $tree;
    }

    /** @return array<class-string, list<Model>> */
    private static function groupByClass(array $models): array
    {
        $groups = [];

        foreach ($models as $model) {
            $groups[$model::class][] = $model;
        }

        return $groups;
    }
}
