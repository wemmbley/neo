<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Query;

use Closure;
use Neo\Information\Persistence\Database\Connection;

/**
 * Построитель запросов. Результаты get() это массивы строк (array<string, mixed>).
 */
class Builder
{
    public array $columns = ['*'];
    public bool $distinct = false;
    public ?string $from = null;
    public array $joins = [];
    public array $wheres = [];
    public array $groups = [];
    public array $orders = [];
    public ?int $limit = null;
    public ?int $offset = null;
    public ?array $aggregate = null;

    private const OPERATORS = [
        '=', '<', '>', '<=', '>=', '<>', '!=', 'like', 'not like', 'ilike',
    ];

    public function __construct(public readonly Connection $connection, ?string $table = null)
    {
        $this->from = $table;
    }

    public function table(string $table): static
    {
        $this->from = $table;

        return $this;
    }

    public function select(string|Raw ...$columns): static
    {
        $this->columns = $columns ?: ['*'];

        return $this;
    }

    public function addSelect(string|Raw ...$columns): static
    {
        $this->columns = $this->columns === ['*'] ? $columns : [...$this->columns, ...$columns];

        return $this;
    }

    public function distinct(): static
    {
        $this->distinct = true;

        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'inner'): static
    {
        $this->joins[] = compact('type', 'table', 'first', 'operator', 'second');

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    # ---------------------------------------------------------------- where

    public function where(string|Closure|array|Raw $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        if (is_array($column)) {
            foreach ($column as $k => $v) {
                $this->where($k, '=', $v, $boolean);
            }

            return $this;
        }

        if ($column instanceof Closure) {
            $nested = new static($this->connection, $this->from);
            $column($nested);
            $this->wheres[] = ['type' => 'nested', 'wheres' => $nested->wheres, 'boolean' => $boolean];

            return $this;
        }

        if (func_num_args() === 2) {
            [$value, $operator] = [$operator, '='];
        }

        $operator = strtolower((string) $operator);
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new \InvalidArgumentException("Неизвестный оператор where: {$operator}");
        }

        if ($value === null) {
            return $this->whereNull((string) $column, $boolean, $operator !== '=');
        }

        $this->wheres[] = ['type' => 'basic', 'column' => (string) $column, 'operator' => $operator, 'value' => $value, 'boolean' => $boolean];

        return $this;
    }

    public function orWhere(string|Closure|array $column, mixed $operator = null, mixed $value = null): static
    {
        return func_num_args() === 2
            ? $this->where($column, '=', $operator, 'or')
            : $this->where($column, $operator, $value, 'or');
    }

    public function whereColumn(string $first, string $operator, string $second, string $boolean = 'and'): static
    {
        $this->wheres[] = ['type' => 'column', 'first' => $first, 'operator' => $operator, 'second' => $second, 'boolean' => $boolean];

        return $this;
    }

    public function whereIn(string $column, array $values, string $boolean = 'and', bool $not = false): static
    {
        $this->wheres[] = ['type' => 'in', 'column' => $column, 'values' => $values, 'boolean' => $boolean, 'not' => $not];

        return $this;
    }

    public function whereNotIn(string $column, array $values, string $boolean = 'and'): static
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    public function whereNull(string $column, string $boolean = 'and', bool $not = false): static
    {
        $this->wheres[] = ['type' => 'null', 'column' => $column, 'boolean' => $boolean, 'not' => $not];

        return $this;
    }

    public function whereNotNull(string $column, string $boolean = 'and'): static
    {
        return $this->whereNull($column, $boolean, true);
    }

    public function whereBetween(string $column, array $values, string $boolean = 'and', bool $not = false): static
    {
        $this->wheres[] = ['type' => 'between', 'column' => $column, 'values' => array_values($values), 'boolean' => $boolean, 'not' => $not];

        return $this;
    }

    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'and'): static
    {
        $this->wheres[] = ['type' => 'raw', 'sql' => $sql, 'bindings' => $bindings, 'boolean' => $boolean];

        return $this;
    }

    # ------------------------------------------------- group / order / limit

    public function groupBy(string ...$columns): static
    {
        array_push($this->groups, ...$columns);

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $this->orders[] = compact('column', 'direction');

        return $this;
    }

    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'desc');
    }

    public function latest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'desc');
    }

    public function oldest(string $column = 'created_at'): static
    {
        return $this->orderBy($column, 'asc');
    }

    public function limit(int $value): static
    {
        $this->limit = max(0, $value);

        return $this;
    }

    public function take(int $value): static
    {
        return $this->limit($value);
    }

    public function offset(int $value): static
    {
        $this->offset = max(0, $value);

        return $this;
    }

    public function skip(int $value): static
    {
        return $this->offset($value);
    }

    public function forPage(int $page, int $perPage = 15): static
    {
        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }

    # --------------------------------------------------------------- reading

    public function toSql(): string
    {
        return $this->grammar()->select($this)[0];
    }

    public function getBindings(): array
    {
        return $this->grammar()->select($this)[1];
    }

    /** @return array<int, array<string, mixed>> */
    public function get(): array
    {
        [$sql, $bindings] = $this->grammar()->select($this);

        return $this->connection->select($sql, $bindings);
    }

    public function first(): ?array
    {
        return (clone $this)->limit(1)->get()[0] ?? null;
    }

    public function find(int|string $id, string $key = 'id'): ?array
    {
        return (clone $this)->where($key, '=', $id)->first();
    }

    public function value(string $column): mixed
    {
        $row = (clone $this)->select($column)->first();

        return $row === null ? null : reset($row);
    }

    public function pluck(string $column, ?string $key = null): array
    {
        $rows = $this->get();
        $name = str_contains($column, '.') ? substr(strrchr($column, '.'), 1) : $column;
        $keyName = $key !== null && str_contains($key, '.') ? substr(strrchr($key, '.'), 1) : $key;
        $out = [];

        foreach ($rows as $row) {
            if ($keyName !== null) {
                $out[$row[$keyName]] = $row[$name] ?? null;
            } else {
                $out[] = $row[$name] ?? null;
            }
        }

        return $out;
    }

    public function exists(): bool
    {
        return (clone $this)->count() > 0;
    }

    public function count(string $column = '*'): int
    {
        return (int) $this->aggregateValue('count', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregateValue('max', $column);
    }

    public function min(string $column): mixed
    {
        return $this->aggregateValue('min', $column);
    }

    public function sum(string $column): int|float
    {
        return $this->aggregateValue('sum', $column) ?? 0;
    }

    public function avg(string $column): int|float|null
    {
        return $this->aggregateValue('avg', $column);
    }

    private function aggregateValue(string $function, string $column): mixed
    {
        $q = clone $this;
        $q->aggregate = compact('function', 'column');
        $q->limit = $q->offset = null;
        $row = $q->get()[0] ?? null;

        return $row['aggregate'] ?? null;
    }

    # --------------------------------------------------------------- writing

    /** Вставка одной строки или нескольких (массив массивов). */
    public function insert(array $values): bool
    {
        if ($values === []) {
            return true;
        }

        $rows = array_is_list($values) ? $values : [$values];
        [$sql, $bindings] = $this->grammar()->insert((string) $this->from, $rows);

        return $this->connection->statement($sql, $bindings);
    }

    public function insertGetId(array $values): int|string
    {
        $this->insert($values);

        $id = $this->connection->lastInsertId();

        return ctype_digit($id) ? (int) $id : $id;
    }

    public function update(array $values): int
    {
        [$sql, $bindings] = $this->grammar()->update($this, $values);

        return $this->connection->affectingStatement($sql, $bindings);
    }

    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        $w = $this->grammar()->wrap($column);

        return $this->update([$column => new Raw($w . ' + ' . ($amount + 0)), ...$extra]);
    }

    public function delete(): int
    {
        [$sql, $bindings] = $this->grammar()->delete($this);

        return $this->connection->affectingStatement($sql, $bindings);
    }

    public function newQuery(): static
    {
        return new static($this->connection, $this->from);
    }

    private function grammar(): Grammar
    {
        return $this->connection->grammar;
    }
}
