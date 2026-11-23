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

        $hasPrimary = false;
        foreach ($bp->indexes as $idx) {
            if ($idx['type'] === 'primary') {
                $defs[] = 'primary key (' . $this->cols($idx['columns']) . ')';
                $hasPrimary = true;
            }
        }

        # ->primary() на колонке без автоинкремента.
        $flagged = array_values(array_map(
            static fn (ColumnDefinition $c) => $c->name,
            array_filter($bp->columns, static fn (ColumnDefinition $c) => $c->primary && !$c->autoIncrement),
        ));
        if (!$hasPrimary && $flagged !== []) {
            $defs[] = 'primary key (' . $this->cols($flagged) . ')';
        }

        foreach ($bp->columns as $c) {
            if ($c->on !== null) {
                $fk = 'constraint ' . $this->grammar->wrap($c->fkName ?? $table . '_' . $c->name . '_foreign')
                    . ' foreign key (' . $this->grammar->wrap($c->name) . ') references '
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

    public function foreignKeySql(string $table, string $name, array $fk): string
    {
        $sql = 'constraint ' . $this->grammar->wrap($name) . ' foreign key (' . $this->grammar->wrap($fk['column']) . ') references '
            . $this->grammar->wrap($fk['on']) . ' (' . $this->grammar->wrap($fk['references']) . ')';

        return $sql . (!empty($fk['onDelete']) ? ' on delete ' . $fk['onDelete'] : '') . (!empty($fk['onUpdate']) ? ' on update ' . $fk['onUpdate'] : '');
    }

    public function indexCreateSql(string $table, string $name, string $type, array $columns): string
    {
        return $this->indexSql($type, $table, $columns, $name);
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

    /** Тип колонки в SQL, включая `unsigned` для MySQL. */
    public function sqlType(ColumnDefinition $c): string
    {
        $type = $this->type($c);

        if ($this->mysql() && $c->unsigned && in_array($c->type, ['integer', 'bigInteger', 'float', 'decimal'], true)) {
            $type .= ' unsigned';
        }

        return $type;
    }

    /** Значение по умолчанию как SQL-текст или null, если не задано. */
    public function defaultSql(ColumnDefinition $c): ?string
    {
        return $c->useCurrent ? 'current_timestamp' : ($c->hasDefault ? $this->defaultValue($c->default) : null);
    }

    /** Определение колонки. $withPrimary = false нужен для MySQL MODIFY: ключ уже существует. */
    public function column(ColumnDefinition $c, bool $withPrimary = true): string
    {
        $sql = $this->grammar->wrap($c->name) . ' ' . $this->sqlType($c);

        $sql .= $c->nullable ? ' null' : ' not null';

        if ($c->useCurrent) {
            $sql .= ' default current_timestamp';
        } elseif ($c->hasDefault) {
            $sql .= ' default ' . $this->defaultValue($c->default);
        }

        if ($c->autoIncrement) {
            $sql .= $this->mysql() ? ($withPrimary ? ' auto_increment primary key' : ' auto_increment') : ' primary key autoincrement';
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
