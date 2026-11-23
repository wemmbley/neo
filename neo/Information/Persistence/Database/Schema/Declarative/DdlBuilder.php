<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Schema\Declarative;

use Neo\Information\Persistence\Database\Query\Grammar;

/**
 * SQL для операций {@see Operation} по снимкам таблиц. mysql: прямой ALTER;
 * sqlite: простые операции напрямую, остальное через пересборку (см. {@see SqliteRebuilder}).
 */
final class DdlBuilder
{
    public function __construct(private readonly string $driver, private readonly Grammar $grammar) {}

    public function mysql(): bool
    {
        return $this->driver === 'mysql';
    }

    private function w(string $name): string
    {
        return $this->grammar->wrap($name);
    }

    private function cols(array $columns): string
    {
        return implode(', ', array_map($this->w(...), $columns));
    }

    /** @param array<string, mixed> $def */
    public function column(string $name, array $def): string
    {
        $sql = $this->w($name) . ' ' . $def['sql'] . (($def['nullable'] ?? false) ? ' null' : ' not null');

        if (($def['default'] ?? null) !== null) {
            $sql .= ' default ' . $def['default'];
        }

        if (!empty($def['auto'])) {
            $sql .= $this->mysql() ? ' auto_increment' : ' primary key autoincrement';
        }

        if ($this->mysql() && !empty($def['comment'])) {
            $sql .= " comment '" . str_replace("'", "''", (string) $def['comment']) . "'";
        }

        return $sql;
    }

    /** @param array<string, mixed> $fk */
    private function foreign(string $name, array $fk): string
    {
        return 'constraint ' . $this->w($name) . ' foreign key (' . $this->w($fk['column']) . ') references '
            . $this->w($fk['on']) . ' (' . $this->w($fk['references']) . ')'
            . (!empty($fk['onDelete']) ? ' on delete ' . $fk['onDelete'] : '')
            . (!empty($fk['onUpdate']) ? ' on update ' . $fk['onUpdate'] : '');
    }

    private function indexSql(string $table, string $name, array $index): string
    {
        return ($index['type'] === 'unique' ? 'create unique index ' : 'create index ')
            . $this->w($name) . ' on ' . $this->w($table) . ' (' . $this->cols($index['columns']) . ')';
    }

    /**
     * @param array<string, mixed> $def columns / indexes / foreign / primary
     * @return list<string>
     */
    public function createTable(string $table, array $def, bool $withIndexes = true): array
    {
        $parts = [];
        $autoColumns = [];

        foreach ($def['columns'] as $name => $c) {
            $parts[] = $this->column($name, $c);
            if (!empty($c['auto'])) {
                $autoColumns[] = $name;
            }
        }

        # sqlite держит ключ автоинкремента внутри колонки, остальные ключи: отдельным определением.
        $primary = $this->mysql() ? $def['primary'] : array_values(array_diff($def['primary'], $autoColumns));
        if ($primary !== []) {
            $parts[] = 'primary key (' . $this->cols($primary) . ')';
        }

        foreach ($def['foreign'] as $name => $fk) {
            $parts[] = $this->foreign($name, $fk);
        }

        $out = ['create table ' . $this->w($table) . ' (' . implode(', ', $parts) . ')'];

        if ($withIndexes) {
            foreach ($def['indexes'] as $name => $index) {
                $out[] = $this->indexSql($table, $name, $index);
            }
        }

        return $out;
    }

    /** Требует ли операция пересборки таблицы (только sqlite). */
    public function needsRebuild(Operation $op): bool
    {
        if ($this->mysql()) {
            return false;
        }

        return match ($op->kind) {
            'changeColumn', 'dropColumn', 'addForeign', 'dropForeign' => true,
            'addColumn' => !$this->sqliteCanAdd($op->get('def')),
            default => false,
        };
    }

    /** @param array<string, mixed> $def */
    private function sqliteCanAdd(array $def): bool
    {
        return empty($def['auto'])
            && ($def['nullable'] || ($def['default'] ?? null) !== null)
            && ($def['default'] ?? null) !== 'current_timestamp';
    }

    /**
     * Готовые SQL-выражения операции. Для sqlite-операций, требующих пересборки, бросает исключение.
     *
     * @return list<string>
     */
    public function statements(Operation $op): array
    {
        $t = $op->table;
        $wt = $this->w($t);

        if ($this->needsRebuild($op)) {
            throw new \LogicException("Операция {$op->kind} для sqlite выполняется пересборкой таблицы.");
        }

        return match ($op->kind) {
            'createTable' => $this->createTable($t, $op->get('def')),
            'dropTable' => ['drop table ' . $wt],
            'addColumn' => ["alter table {$wt} add column " . $this->column($op->get('column'), $op->get('def'))],
            'dropColumn' => ["alter table {$wt} drop column " . $this->w($op->get('column'))],
            'changeColumn' => ["alter table {$wt} modify column " . $this->column($op->get('column'), $op->get('to'))],
            'renameColumn' => $this->mysql()
                ? ["alter table {$wt} change column " . $this->w($op->get('from')) . ' ' . $this->column($op->get('to'), $op->get('def'))]
                : ["alter table {$wt} rename column " . $this->w($op->get('from')) . ' to ' . $this->w($op->get('to'))],
            'addIndex' => [$this->indexSql($t, $op->get('name'), $op->get('index'))],
            'dropIndex' => [$this->mysql()
                ? 'drop index ' . $this->w($op->get('name')) . " on {$wt}"
                : 'drop index if exists ' . $this->w($op->get('name'))],
            'addForeign' => ["alter table {$wt} add " . $this->foreign($op->get('name'), $op->get('fk'))],
            'dropForeign' => ["alter table {$wt} drop foreign key " . $this->w($op->get('name'))],
            default => throw new \LogicException("Неизвестная операция {$op->kind}"),
        };
    }

    public function renameTable(string $from, string $to): string
    {
        return 'alter table ' . $this->w($from) . ' rename to ' . $this->w($to);
    }

    public function dropTable(string $table): string
    {
        return 'drop table ' . $this->w($table);
    }

    /** @param array<string, mixed> $index */
    public function createIndex(string $table, string $name, array $index): string
    {
        return $this->indexSql($table, $name, $index);
    }
}
