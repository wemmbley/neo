<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Relations;

use Closure;
use LogicException;
use Neo\Information\Persistence\ORM\Builder;
use Neo\Information\Persistence\ORM\EagerLoader;
use Neo\Information\Persistence\ORM\Model;

/**
 * Базовое отношение. Методы билдера (where, orderBy, count ...) проксируются в запрос.
 *
 * Для одной модели отношение добавляет `where parent_key = ?` (addConstraints). Для набора
 * моделей (eager loading) вместо этого строится один запрос `where key in (...)` и результат
 * раскладывается по родителям в PHP: число запросов не зависит от числа родителей.
 *
 * @mixin Builder
 */
abstract class Relation
{
    private static bool $constraints = true;

    protected Builder $query;

    public function __construct(protected readonly Model $parent, protected readonly Model $related)
    {
        $this->query = ($related::class)::query();
        $this->baseQuery();

        if (self::$constraints) {
            $this->addConstraints();
        }
    }

    /** Создать отношение без привязки к конкретному родителю (для eager loading). */
    public static function noConstraints(Closure $callback): mixed
    {
        $previous = self::$constraints;
        self::$constraints = false;

        try {
            return $callback();
        } finally {
            self::$constraints = $previous;
        }
    }

    /** join / select, нужные и для одной модели, и для набора. */
    protected function baseQuery(): void {}

    /** Условие для одного родителя. */
    abstract protected function addConstraints(): void;

    abstract public function isMany(): bool;

    /**
     * Как связаны таблицы (для запросов "есть связанная запись", см. ResourceAPI).
     * Поддерживают BelongsTo, HasOne, HasMany и BelongsToMany.
     *
     * Ключи: `type` (`belongs_to` | `has` | `pivot`), `parent` (колонка родителя), `related` (колонка
     * связанной таблицы), а для `pivot` ещё `pivot` (таблица), `pivot_parent`, `pivot_related`.
     *
     * @return array<string, string>
     */
    public function linkage(): array
    {
        throw new LogicException(static::class . ' не описывает связь таблиц.');
    }

    /** Результат для `$model->relation`: модель|null или коллекция. */
    abstract public function getResults(): mixed;

    /** Условие `in (...)` для набора родителей; false: ключей нет, запрос не нужен. */
    protected function addEagerConstraints(array $parents): bool
    {
        throw new LogicException(static::class . ' не поддерживает eager loading.');
    }

    /** Значение в связанной модели, по которому она привязана к родителю. */
    protected function dictionaryKey(Model $result): mixed
    {
        throw new LogicException(static::class . ' не поддерживает eager loading.');
    }

    /** Значение в родителе, с которым сопоставляется dictionaryKey(). */
    protected function parentKey(Model $parent): mixed
    {
        throw new LogicException(static::class . ' не поддерживает eager loading.');
    }

    /** Убрать служебные атрибуты, добавленные для сопоставления. */
    protected function cleanup(Model $result): void {}

    /** @return list<Model> */
    protected function models(): array
    {
        return $this->query->getModels();
    }

    /**
     * Загрузить отношение сразу для всех $parents: один запрос (плюс по запросу на каждый
     * вложенный уровень $nested).
     *
     * @param list<Model>                 $parents
     * @param array<string, Closure|null> $nested
     */
    public function eagerLoad(array $parents, string $name, ?Closure $constraint, array $nested): void
    {
        $constraint && $constraint($this->query);

        $results = $this->addEagerConstraints($parents) ? $this->models() : [];

        EagerLoader::load($results, $nested);

        $dictionary = [];
        foreach ($results as $result) {
            $key = $this->dictionaryKey($result);
            $this->cleanup($result);

            if ($key !== null) {
                $dictionary[$key][] = $result;
            }
        }

        foreach ($parents as $parent) {
            $key = $this->parentKey($parent);
            $items = $key === null ? [] : ($dictionary[$key] ?? []);

            $parent->setRelation($name, $this->isMany() ? $this->related->newCollection($items) : ($items[0] ?? null));
        }
    }

    /** Условие "колонка = ключ родителя"; пустой ключ (родитель не сохранён) даёт пустой результат. */
    protected function whereParent(string $column, mixed $value): void
    {
        $value === null ? $this->query->whereRaw('0 = 1') : $this->query->where($column, '=', $value);
    }

    /** @param list<Model> $parents @return list<mixed> уникальные ключи без null */
    protected function keys(array $parents, Closure $get): array
    {
        $keys = [];

        foreach ($parents as $parent) {
            $value = $get($parent);

            if ($value !== null) {
                $keys[(string) $value] = $value;
            }
        }

        return array_values($keys);
    }

    public function getQuery(): Builder
    {
        return $this->query;
    }

    public function __call(string $method, array $args): mixed
    {
        $result = $this->query->{$method}(...$args);

        return $result instanceof Builder ? $this : $result;
    }
}
