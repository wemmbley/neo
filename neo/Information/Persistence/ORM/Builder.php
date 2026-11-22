<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM;

use Closure;
use Neo\Information\Persistence\Database\Query\Builder as QueryBuilder;
use Neo\Information\Persistence\ORM\Exceptions\ModelNotFoundException;

/**
 * Query builder модели. Всё, чего здесь нет (where, orderBy, count, pluck ...),
 * проксируется в Database Builder; цепочки остаются на этом классе.
 *
 * @template TModel of Model
 * @mixin QueryBuilder
 */
class Builder
{
    /** Классы пагинаторов (Laravel-совместимые конструкторы). Менять через usePaginators(). */
    private static string $lengthAwarePaginator = 'App\\Information\\LengthAwarePaginator';
    private static string $simplePaginator = 'App\\Information\\Paginator';

    /** @var array<int|string, string|Closure|null> */
    private array $eagerLoad;

    /** @param TModel $model */
    public function __construct(private readonly Model $model, private QueryBuilder $query)
    {
        $this->eagerLoad = $model->getWith();
    }

    public static function usePaginators(string $lengthAware, string $simple): void
    {
        self::$lengthAwarePaginator = $lengthAware;
        self::$simplePaginator = $simple;
    }

    public function getModel(): Model
    {
        return $this->model;
    }

    public function toBase(): QueryBuilder
    {
        return $this->query;
    }

    /**
     * Загрузить отношения сразу, одним запросом на отношение.
     *
     *     Post::with('author', 'comments.user')->get();
     *     Post::with(['comments' => fn (Builder $q) => $q->where('approved', true)])->get();
     */
    public function with(string|array ...$relations): static
    {
        $this->eagerLoad = [...$this->eagerLoad, ...EagerLoader::normalize($relations)];

        return $this;
    }

    public function without(string ...$relations): static
    {
        $this->eagerLoad = array_filter(
            $this->eagerLoad,
            static fn ($value, $key) => !in_array(is_int($key) ? $value : $key, $relations, true),
            ARRAY_FILTER_USE_BOTH,
        );

        return $this;
    }

    /** @return list<TModel> */
    public function getModels(): array
    {
        $models = $this->hydrate($this->query->get());

        EagerLoader::load($models, $this->eagerLoad);

        return $models;
    }

    /** Коллекция моделей (класс задаётся Model::useCollection()). */
    public function get(): mixed
    {
        return $this->model->newCollection($this->getModels());
    }

    /** @return list<TModel> */
    public function hydrate(array $rows): array
    {
        return array_map(fn (array $row) => $this->model->newFromRow($row), $rows);
    }

    /** @return TModel|null */
    public function first(): ?Model
    {
        $row = $this->query->first();

        if ($row === null) {
            return null;
        }

        $model = $this->model->newFromRow($row);
        EagerLoader::load([$model], $this->eagerLoad);

        return $model;
    }

    /** @return TModel|null */
    public function find(int|string $id): ?Model
    {
        return (clone $this)->where($this->model->getQualifiedKeyName(), '=', $id)->first();
    }

    /** @return TModel */
    public function findOrFail(int|string $id): Model
    {
        return $this->find($id) ?? throw new ModelNotFoundException($this->model::class, $id);
    }

    /** @return TModel */
    public function firstOrFail(): Model
    {
        return $this->first() ?? throw new ModelNotFoundException($this->model::class);
    }

    /** @return TModel */
    public function create(array $attributes): Model
    {
        $model = $this->model->newInstance($attributes);
        $model->save();

        return $model;
    }

    /** @return TModel */
    public function firstOrCreate(array $attributes, array $values = []): Model
    {
        return (clone $this)->where($attributes)->first() ?? $this->create([...$attributes, ...$values]);
    }

    /** @return TModel */
    public function updateOrCreate(array $attributes, array $values = []): Model
    {
        $model = (clone $this)->where($attributes)->first();

        if ($model === null) {
            return $this->create([...$attributes, ...$values]);
        }

        $model->update($values);

        return $model;
    }

    # ------------------------------------------------------------ pagination

    /**
     * Страница с общим числом элементов: два запроса (count и выборка страницы).
     * Использует пагинатор коллекции: new LengthAwarePaginator($items, $total, $perPage, $page, $options).
     */
    public function paginate(int $perPage = 15, ?int $page = null, array $options = [], string $pageName = 'page'): object
    {
        $class = self::$lengthAwarePaginator;

        $page ??= (int) $class::resolveCurrentPage($pageName);
        $options += ['path' => $class::resolveCurrentPath(), 'pageName' => $pageName];

        $total = $this->query->count();
        $items = $total > 0
            ? (clone $this)->forPage($page, $perPage)->get()
            : $this->model->newCollection();

        return new $class($items, $total, $perPage, $page, $options);
    }

    /** Страница без count: запрашивается на одну запись больше, чтобы узнать, есть ли следующая. */
    public function simplePaginate(int $perPage = 15, ?int $page = null, array $options = [], string $pageName = 'page'): object
    {
        $class = self::$simplePaginator;

        $page ??= (int) $class::resolveCurrentPage($pageName);
        $options += ['path' => $class::resolveCurrentPath(), 'pageName' => $pageName];

        $items = (clone $this)->offset(($page - 1) * $perPage)->limit($perPage + 1)->get();

        return new $class($items, $perPage, $page, $options);
    }

    public function __clone()
    {
        # Цепочки не должны делить состояние базового билдера.
        $this->query = clone $this->query;
    }

    public function __call(string $method, array $args): mixed
    {
        $result = $this->query->{$method}(...$args);

        return $result instanceof QueryBuilder ? $this : $result;
    }
}
