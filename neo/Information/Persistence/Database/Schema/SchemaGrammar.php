<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Schema;

use Neo\Information\Persistence\Database\Query\Grammar;

/**
 * DDL для sqlite и mysql. Возвращает списки SQL-выражений.
 */
final class SchemaGrammar
{
    public function __construct(private readonly string $driver, private readonly Grammar $grammar) {}

    private function mysql(): bool
    {
        return $this->driver === 'mysql';
    }

    /** @return list<string> */
    public function create(Blueprint $bp, string $table): array
    {
        $defs = array_map(fn (ColumnDefinition $c) => $this->column($c), $bp->columns);

        foreach ($bp->indexes as $idx) {
            if ($idx['type'] === 'primary') {
                $defs[] = 'primary key (' . $this->cols($idx['columns']) . ')';
            }
        }

        foreach ($bp->columns as $c) {
            if ($c->on !== null) {
                $fk = 'foreign key (' . $this->grammar->wrap($c->name) . ') references '
                    . $this->grammar->wrap($c->on) . ' (' . $this->grammar->wrap((string) $c->references) . ')';
                $fk .= $c->onDelete ? ' on delete ' . $c->onDelete : '';
                $fk .= $c->onUpdate ? ' on update ' . $c->onUpdate : '';
                $defs[] = $fk;
            }
        }

        $sql = 'create table ' . $this->grammar->wrap($table) . ' (' . implode(', ', $defs) . ')';

        return [$sql, ...$this->indexStatements($bp, $table)];
    }

    /** @return list<string> */
    public function alter(Blueprint $bp, string $table): array
    {
        $out = [];
        $t = $this->grammar->wrap($table);

        foreach ($bp->columns as $c) {
            $out[] = "alter table {$t} add column " . $this->column($c);
        }
        foreach ($bp->dropColumns as $name) {
            $out[] = "alter table {$t} drop column " . $this->grammar->wrap($name);
        }

        return [...$out, ...$this->indexStatements($bp, $table)];
    }

    public function drop(string $table): string
    {
        return 'drop table ' . $this->grammar->wrap($table);
    }

    public function dropIfExists(string $table): string
    {
        return 'drop table if exists ' . $this->grammar->wrap($table);
    }

    public function rename(string $from, string $to): string
    {
        return 'alter table ' . $this->grammar->wrap($from) . ' rename to ' . $this->grammar->wrap($to);
    }

    private function indexStatements(Blueprint $bp, string $table): array
    {
        $out = [];

        foreach ($bp->columns as $c) {
            if ($c->unique && !$c->primary) {
                $out[] = $this->indexSql('unique', $table, [$c->name], null);
            }
            if ($c->index) {
                $out[] = $this->indexSql('index', $table, [$c->name], null);
            }
        }
        foreach ($bp->indexes as $i) {
            if ($i['type'] !== 'primary') {
                $out[] = $this->indexSql($i['type'], $table, $i['columns'], $i['name']);
            }
        }

        return $out;
    }

    private function indexSql(string $type, string $table, array $columns, ?string $name): string
    {
        $name ??= $table . '_' . implode('_', $columns) . '_' . $type;

        return ($type === 'unique' ? 'create unique index ' : 'create index ')
            . $this->grammar->wrap($name) . ' on ' . $this->grammar->wrap($table)
            . ' (' . $this->cols($columns) . ')';
    }

    private function cols(array $columns): string
    {
        return implode(', ', array_map($this->grammar->wrap(...), $columns));
    }

    private function column(ColumnDefinition $c): string
    {
        $sql = $this->grammar->wrap($c->name) . ' ' . $this->type($c);

        if ($this->mysql() && $c->unsigned && in_array($c->type, ['integer', 'bigInteger', 'float', 'decimal'], true)) {
            $sql .= ' unsigned';
        }

        $sql .= $c->nullable ? ' null' : ' not null';

        if ($c->useCurrent) {
            $sql .= ' default current_timestamp';
        } elseif ($c->hasDefault) {
            $sql .= ' default ' . $this->defaultValue($c->default);
        }

        if ($c->autoIncrement) {
            $sql .= $this->mysql() ? ' auto_increment primary key' : ' primary key autoincrement';
        }

        if ($this->mysql() && $c->comment !== null) {
            $sql .= " comment '" . str_replace("'", "''", $c->comment) . "'";
        }

        return $sql;
    }

    private function type(ColumnDefinition $c): string
    {
        $p = $c->parameters;

        if (!$this->mysql()) {
            return match ($c->type) {
                'integer', 'bigInteger' => 'integer',
                'string' => 'varchar(' . ($p['length'] ?? 255) . ')',
                'text', 'json' => 'text',
                'boolean' => 'tinyint(1)',
                'float' => 'float',
                'decimal' => 'numeric(' . $p['total'] . ', ' . $p['places'] . ')',
                'date' => 'date',
                'dateTime', 'timestamp' => 'datetime',
                default => throw new \InvalidArgumentException("Неизвестный тип колонки: {$c->type}"),
            };
        }

        return match ($c->type) {
            'integer' => 'int',
            'bigInteger' => 'bigint',
            'string' => 'varchar(' . ($p['length'] ?? 255) . ')',
            'text' => 'text',
            'json' => 'json',
            'boolean' => 'tinyint(1)',
            'float' => 'double',
            'decimal' => 'decimal(' . $p['total'] . ', ' . $p['places'] . ')',
            'date' => 'date',
            'dateTime' => 'datetime',
            'timestamp' => 'timestamp',
            default => throw new \InvalidArgumentException("Неизвестный тип колонки: {$c->type}"),
        };
    }

    private function defaultValue(mixed $v): string
    {
        return match (true) {
            $v === null => 'null',
            is_bool($v) => $v ? '1' : '0',
            is_int($v), is_float($v) => (string) $v,
            default => "'" . str_replace("'", "''", (string) $v) . "'",
        };
    }
}
