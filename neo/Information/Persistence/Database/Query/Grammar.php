<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Query;

/**
 * Компилятор запросов Builder в SQL. Различия драйверов сводятся к кавычкам.
 */
class Grammar
{
    public function __construct(private readonly string $quote = '"') {}

    /** @return array{0: string, 1: array} SQL и биндинги */
    public function select(Builder $q): array
    {
        $b = [];
        $sql = 'select ' . ($q->distinct ? 'distinct ' : '');

        if ($q->aggregate !== null) {
            $col = $q->aggregate['column'] === '*' ? '*' : $this->wrap($q->aggregate['column']);
            $sql .= $q->aggregate['function'] . '(' . $col . ') as aggregate';
        } else {
            $sql .= implode(', ', array_map($this->wrap(...), $q->columns));
        }

        $sql .= ' from ' . $this->wrap((string) $q->from);

        foreach ($q->joins as $j) {
            $sql .= " {$j['type']} join " . $this->wrap($j['table'])
                . ' on ' . $this->wrap($j['first']) . " {$j['operator']} " . $this->wrap($j['second']);
        }

        [$w, $wb] = $this->wheres($q);
        $sql .= $w;
        $b = [...$b, ...$wb];

        if ($q->groups) {
            $sql .= ' group by ' . implode(', ', array_map($this->wrap(...), $q->groups));
        }

        if ($q->orders && $q->aggregate === null) {
            $sql .= ' order by ' . implode(', ', array_map(
                fn (array $o) => $o['raw'] ?? ($this->wrap($o['column']) . ' ' . $o['direction']),
                $q->orders,
            ));
        }

        if ($q->limit !== null || $q->offset !== null) {
            $sql .= ' limit ' . ($q->limit ?? PHP_INT_MAX);
            if ($q->offset !== null) {
                $sql .= ' offset ' . $q->offset;
            }
        }

        return [$sql, $b];
    }

    /** @param array<int, array<string, mixed>> $rows */
    public function insert(string $table, array $rows): array
    {
        $columns = array_keys($rows[0]);
        $place = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $b = [];

        foreach ($rows as $row) {
            foreach ($columns as $c) {
                $b[] = $row[$c] ?? null;
            }
        }

        $sql = 'insert into ' . $this->wrap($table)
            . ' (' . implode(', ', array_map($this->wrap(...), $columns)) . ') values '
            . implode(', ', array_fill(0, count($rows), $place));

        return [$sql, $b];
    }

    public function update(Builder $q, array $values): array
    {
        $sets = [];
        $b = [];

        foreach ($values as $col => $val) {
            if ($val instanceof Raw) {
                $sets[] = $this->wrap($col) . ' = ' . $val->value;
            } else {
                $sets[] = $this->wrap($col) . ' = ?';
                $b[] = $val;
            }
        }

        [$w, $wb] = $this->wheres($q);

        return ['update ' . $this->wrap((string) $q->from) . ' set ' . implode(', ', $sets) . $w, [...$b, ...$wb]];
    }

    public function delete(Builder $q): array
    {
        [$w, $wb] = $this->wheres($q);

        return ['delete from ' . $this->wrap((string) $q->from) . $w, $wb];
    }

    /** @return array{0: string, 1: array} */
    public function wheres(Builder $q): array
    {
        if (!$q->wheres) {
            return ['', []];
        }

        [$sql, $b] = $this->compileWhereList($q->wheres);

        return [' where ' . $sql, $b];
    }

    private function compileWhereList(array $wheres): array
    {
        $parts = [];
        $b = [];

        foreach ($wheres as $i => $w) {
            $piece = '';

            switch ($w['type']) {
                case 'basic':
                    $piece = $this->wrap($w['column']) . ' ' . $w['operator'] . ' ?';
                    $b[] = $w['value'];
                    break;
                case 'column':
                    $piece = $this->wrap($w['first']) . ' ' . $w['operator'] . ' ' . $this->wrap($w['second']);
                    break;
                case 'in':
                    if ($w['values'] === []) {
                        $piece = $w['not'] ? '1 = 1' : '0 = 1';
                        break;
                    }
                    $piece = $this->wrap($w['column']) . ($w['not'] ? ' not in ' : ' in ')
                        . '(' . implode(', ', array_fill(0, count($w['values']), '?')) . ')';
                    array_push($b, ...array_values($w['values']));
                    break;
                case 'null':
                    $piece = $this->wrap($w['column']) . ($w['not'] ? ' is not null' : ' is null');
                    break;
                case 'between':
                    $piece = $this->wrap($w['column']) . ($w['not'] ? ' not between ? and ?' : ' between ? and ?');
                    array_push($b, $w['values'][0], $w['values'][1]);
                    break;
                case 'raw':
                    $piece = $w['sql'];
                    array_push($b, ...$w['bindings']);
                    break;
                case 'nested':
                    [$ns, $nb] = $this->compileWhereList($w['wheres']);
                    $piece = '(' . $ns . ')';
                    array_push($b, ...$nb);
                    break;
            }

            $parts[] = ($i === 0 ? '' : $w['boolean'] . ' ') . $piece;
        }

        return [implode(' ', $parts), $b];
    }

    public function wrap(string|Raw $value): string
    {
        if ($value instanceof Raw) {
            return $value->value;
        }

        if (stripos($value, ' as ') !== false) {
            [$name, $alias] = preg_split('/\s+as\s+/i', $value, 2);

            return $this->wrap($name) . ' as ' . $this->wrapSegment($alias);
        }

        return implode('.', array_map($this->wrapSegment(...), explode('.', $value)));
    }

    private function wrapSegment(string $s): string
    {
        if ($s === '*') {
            return $s;
        }

        return $this->quote . str_replace($this->quote, $this->quote . $this->quote, $s) . $this->quote;
    }
}
