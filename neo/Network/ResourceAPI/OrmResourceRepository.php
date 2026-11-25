<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI;

use Neo\Information\Collection\LengthAwarePaginator;
use Neo\Information\Persistence\Database\Query\Builder as QueryBuilder;
use Neo\Information\Persistence\ORM\Builder;
use Neo\Information\Persistence\ORM\Metadata\ModelMetadata;
use Neo\Information\Persistence\ORM\Model;
use Neo\Information\Persistence\ORM\Relations\Relation;

/**
 * Адаптер хранилища: превращает {@see ResourceQuery} в запрос ORM и записи в ответ.
 *
 * Условия по связям это вложенные `EXISTS`-подзапросы с отдельным псевдонимом таблицы
 * на каждый уровень (поэтому работают и связи модели на саму себя). Сортировка по BelongsTo
 * идёт через LEFT JOIN: строки не дублируются, пагинация остаётся точной.
 */
final class OrmResourceRepository
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly ?ResourceAuthorizer $authorizer = null,
    ) {}

    // ------------------------------------------------------------------
    // Чтение
    // ------------------------------------------------------------------

    /** Накладывает запрос на выборку модели (свою или переданную: `Post::filter($params)`). */
    public function query(ResourceAPI $resource, ResourceQuery $query, ?Builder $builder = null): Builder
    {
        $class = $resource->modelClass();
        $builder ??= $class::query();
        $base = $builder->toBase();
        $table = $base->connection->withPrefix($builder->getModel()->getTable());
        $counter = 0;

        $this->group($base, $query->filters, $table, $resource, $counter);

        if ($query->search !== null) {
            $columns = $resource->searchColumns();
            $term = $query->search;

            $base->where(function (QueryBuilder $nested) use ($columns, $table, $term): void {
                foreach ($columns as $column) {
                    $this->like($nested, $table . '.' . $column, '$contains', $term, 'or');
                }
            });
        }

        foreach ($query->custom as [$filter, $value]) {
            $filter->apply($builder, $value);
        }

        $this->sorts($builder, $resource, $query, $table, $counter);

        if ($query->include !== []) {
            $builder->with($query->include);
        }

        return $builder;
    }

    public function paginate(ResourceAPI $resource, ResourceQuery $query, string $path, array $parameters = []): LengthAwarePaginator
    {
        $paginator = $this->query($resource, $query)->paginate(
            $query->perPage,
            $query->page,
            ['path' => $path, 'query' => $parameters],
        );

        return $paginator->through(fn (Model $model) => $this->present($resource, $model, $query));
    }

    public function find(ResourceAPI $resource, string $id, ?ResourceQuery $query = null): ?Model
    {
        $builder = $resource->modelClass()::query();

        if ($query !== null && $query->include !== []) {
            $builder->with($query->include);
        }

        return $builder->find($id);
    }

    // ------------------------------------------------------------------
    // Запись
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $data колонка => значение */
    public function create(ResourceAPI $resource, array $data): Model
    {
        $model = $resource->modelClass()::create($data);

        return $model->fresh() ?? $model;
    }

    /** @param array<string, mixed> $data колонка => значение */
    public function update(ResourceAPI $resource, Model $model, array $data): Model
    {
        $model->fill($data)->save();

        return $model->fresh() ?? $model;
    }

    public function delete(Model $model): void
    {
        $model->delete();
    }

    // ------------------------------------------------------------------
    // Ответ
    // ------------------------------------------------------------------

    /**
     * Запись в том виде, как её видит клиент: только видимые поля под новыми именами,
     * затем загруженные связи (каждая по правилам своего ресурса).
     *
     * @return array<string, mixed>
     */
    public function present(ResourceAPI $resource, Model $model, ?ResourceQuery $query = null): array
    {
        return $this->presentModel($resource, $model, $query?->fields, $this->tree($query?->include ?? []));
    }

    /**
     * @param list<string>|null $columns
     * @param array<string, array> $tree
     */
    private function presentModel(ResourceAPI $resource, Model $model, ?array $columns, array $tree): array
    {
        $fields = ModelMetadata::for($model::class)->fields;
        $values = [];

        foreach ($model->toArray() as $key => $value) {
            $values[isset($fields[$key]) ? $fields[$key]->column : $key] = $value;
        }

        $out = [];

        foreach ($resource->exposed() as $column => $info) {
            if (array_key_exists($column, $values) && ($columns === null || in_array($column, $columns, true))) {
                $out[$info['name']] = $values[$column];
            }
        }

        foreach ($tree as $relation => $nested) {
            $definition = $resource->relations()[$relation] ?? null;
            $related = $definition?->related !== null ? $this->registry->forModel($definition->related) : null;

            if ($related === null) {
                continue;
            }

            $loaded = $model->getRelation($relation);
            $present = fn (Model $m): ?array => $this->authorizer !== null && !$this->authorizer->can('view', $m)
                ? null
                : $this->presentModel($related, $m, null, $nested);

            if ($loaded instanceof Model) {
                $out[$relation] = $present($loaded);
            } elseif (is_iterable($loaded)) {
                $list = [];

                foreach ($loaded as $item) {
                    if ($item instanceof Model && ($row = $present($item)) !== null) {
                        $list[] = $row;
                    }
                }

                $out[$relation] = $list;
            } else {
                $out[$relation] = null;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $paths
     * @return array<string, array>
     */
    private function tree(array $paths): array
    {
        $tree = [];

        foreach ($paths as $path) {
            $node = &$tree;

            foreach (explode('.', $path) as $segment) {
                $node[$segment] ??= [];
                $node = &$node[$segment];
            }

            unset($node);
        }

        return $tree;
    }

    // ------------------------------------------------------------------
    // Условия
    // ------------------------------------------------------------------

    private function group(QueryBuilder $qb, array $group, string $alias, ResourceAPI $resource, int &$counter): void
    {
        $boolean = $group['boolean'];

        foreach ($group['items'] as $item) {
            switch ($item['type']) {
                case 'group':
                    $qb->where(function (QueryBuilder $nested) use ($item, $alias, $resource, &$counter): void {
                        $this->group($nested, $item, $alias, $resource, $counter);
                    }, null, null, $boolean);
                    break;

                case 'cond':
                    $type = $resource->exposed()[$item['column']]['type'];
                    $this->condition($qb, $alias . '.' . $item['column'], $item['op'], $item['value'], $type, $boolean);
                    break;

                case 'relation':
                    $this->relation($qb, $item, $alias, $resource, $counter, $boolean);
                    break;
            }
        }
    }

    private function relation(QueryBuilder $qb, array $item, string $alias, ResourceAPI $resource, int &$counter, string $boolean): void
    {
        $definition = $resource->relations()[$item['relation']];
        $related = $this->registry->forModel($definition->related);
        $link = $this->linkage($resource->modelClass(), $item['relation']);
        $connection = $qb->connection;

        $relatedModel = new ($related->modelClass())();
        $inner = 't' . ++$counter;
        $sub = new QueryBuilder($connection, $connection->withPrefix($relatedModel->getTable()) . ' as ' . $inner);

        if ($link['type'] === 'pivot') {
            $pivot = 'p' . ++$counter;
            $sub->join($connection->withPrefix($link['pivot']) . ' as ' . $pivot, "{$pivot}.{$link['pivot_related']}", '=', "{$inner}.{$link['related']}");
            $sub->whereColumn("{$pivot}.{$link['pivot_parent']}", '=', "{$alias}.{$link['parent']}");
        } else {
            $sub->whereColumn("{$inner}.{$link['related']}", '=', "{$alias}.{$link['parent']}");
        }

        if ($item['exists'] !== false) {
            $this->group($sub, $item['group'], $inner, $related, $counter);
        }

        $qb->whereRaw(($item['exists'] === false ? 'not ' : '') . 'exists (' . $sub->toSql() . ')', $sub->getBindings(), $boolean);
    }

    /** @param class-string<Model> $class @return array<string, string> */
    private function linkage(string $class, string $relation): array
    {
        return Relation::noConstraints(static fn () => (new $class())->relation($relation))->linkage();
    }

    private function condition(QueryBuilder $qb, string $ref, string $op, mixed $value, string $type, string $boolean): void
    {
        $column = $qb->connection->grammar->wrap($ref);
        $text = $type === 'string';

        switch ($op) {
            case '$null':
                $qb->whereNull($ref, $boolean, $value === false);
                return;

            case '$between':
            case '$notBetween':
                $qb->whereBetween($ref, $value, $boolean, $op === '$notBetween');
                return;

            case '$lt':
            case '$lte':
            case '$gt':
            case '$gte':
                $qb->where($ref, ['$lt' => '<', '$lte' => '<=', '$gt' => '>', '$gte' => '>='][$op], $value, $boolean);
                return;

            case '$eqc':
                $sql = match (true) {
                    !$text => "{$column} = ?",
                    $qb->connection->driver() === 'mysql' => "BINARY {$column} = BINARY ?",
                    default => "{$column} = ? collate binary",
                };
                $qb->whereRaw($sql, [$value], $boolean);
                return;

            case '$eq':
            case '$ne':
                $operator = $op === '$eq' ? '=' : '<>';
                $text
                    ? $qb->whereRaw("lower({$column}) {$operator} lower(?)", [$value], $boolean)
                    : $qb->whereRaw("{$column} {$operator} ?", [$value], $boolean);
                return;

            case '$in':
            case '$notIn':
                $marks = implode(', ', array_fill(0, count($value), $text ? 'lower(?)' : '?'));
                $qb->whereRaw(($text ? "lower({$column})" : $column) . ($op === '$notIn' ? ' not in ' : ' in ') . "({$marks})", $value, $boolean);
                return;
        }

        $this->like($qb, $ref, $op, (string) $value, $boolean);
    }

    /** `$contains`, `$startsWith`, `$endsWith` и их варианты без учёта регистра (по умолчанию) и с учётом (на `c`). */
    private function like(QueryBuilder $qb, string $ref, string $op, string $value, string $boolean): void
    {
        $column = $qb->connection->grammar->wrap($ref);
        $sensitive = str_ends_with($op, 'c');
        $not = str_contains($op, 'not') || str_contains($op, 'Not');
        $kind = str_contains($op, 'startsWith') ? 'start' : (str_contains($op, 'endsWith') ? 'end' : 'any');

        if ($sensitive && $qb->connection->driver() !== 'mysql') {
            # SQLite: LIKE не различает регистр, GLOB различает.
            $escaped = preg_replace('/[*?\[]/', '[$0]', $value);
            $pattern = ['start' => $escaped . '*', 'end' => '*' . $escaped, 'any' => '*' . $escaped . '*'][$kind];
            $qb->whereRaw("{$column} " . ($not ? 'not glob' : 'glob') . ' ?', [$pattern], $boolean);

            return;
        }

        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
        $pattern = ['start' => $escaped . '%', 'end' => '%' . $escaped, 'any' => '%' . $escaped . '%'][$kind];
        $operator = ($not ? 'not like' : 'like');

        $sql = $sensitive
            ? "{$column} {$operator} binary ? escape '!'"
            : "lower({$column}) {$operator} lower(?) escape '!'";

        $qb->whereRaw($sql, [$pattern], $boolean);
    }

    // ------------------------------------------------------------------
    // Сортировка
    // ------------------------------------------------------------------

    private function sorts(Builder $builder, ResourceAPI $resource, ResourceQuery $query, string $table, int &$counter): void
    {
        $base = $builder->toBase();
        $grammar = $base->connection->grammar;
        $joined = [];

        foreach ($query->sorts as $sort) {
            $alias = $table;
            $current = $resource;
            $path = '';

            foreach ($sort['relations'] as $relation) {
                $path .= '.' . $relation;

                if (!isset($joined[$path])) {
                    $definition = $current->relations()[$relation];
                    $related = $this->registry->forModel($definition->related);
                    $link = $this->linkage($current->modelClass(), $relation);
                    $name = 's' . ++$counter;

                    $base->leftJoin(
                        $base->connection->withPrefix((new ($related->modelClass())())->getTable()) . ' as ' . $name,
                        "{$name}.{$link['related']}",
                        '=',
                        "{$alias}.{$link['parent']}",
                    );

                    $joined[$path] = [$name, $related];
                }

                [$alias, $current] = $joined[$path];
            }

            $ref = $alias . '.' . $sort['column'];

            if (ResourceAPI::setting('null_last')) {
                $base->orders[] = ['column' => $ref, 'direction' => 'asc', 'raw' => $grammar->wrap($ref) . ' is null'];
            }

            $base->orderBy($ref, $sort['direction']);
        }

        if ($joined !== [] && $base->columns === ['*']) {
            $base->select($table . '.*');
        }

        # Без сортировки по ключу страницы «плывут»: порядок строк с равными значениями не определён.
        $base->orderBy($table . '.' . $builder->getModel()::metadata()->primaryColumn, 'asc');
    }
}
