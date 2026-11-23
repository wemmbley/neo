<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Schema\Declarative;

use Neo\Information\Persistence\Database\Connection;
use Neo\Information\Persistence\Database\Exceptions\DatabaseException;

/**
 * Пересборка таблицы sqlite (рекомендованная схема из документации sqlite):
 * новая таблица → копирование данных → удаление старой → переименование → индексы.
 * Вызывать при PRAGMA foreign_keys = OFF и внутри транзакции.
 */
final class SqliteRebuilder
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DdlBuilder $ddl,
        private readonly Introspector $introspector,
    ) {}

    /** @param list<Operation> $ops операции над одной таблицей */
    public function rebuild(string $table, array $ops): void
    {
        $model = $this->introspector->table($table);

        foreach ($model['columns'] as $name => &$c) {
            $c['src'] = $name;
        }
        unset($c);

        foreach ($ops as $op) {
            $model = $this->apply($model, $op);
        }

        $tmp = '__neo_rebuild_' . $table;
        $this->connection->statement('drop table if exists ' . $this->connection->grammar->wrap($tmp));

        $createDef = $model;
        foreach ($createDef['columns'] as &$c) {
            unset($c['src']);
        }
        unset($c);

        foreach ($this->ddl->createTable($tmp, $createDef, false) as $sql) {
            $this->connection->statement($sql);
        }

        $w = $this->connection->grammar->wrap(...);
        $to = $from = [];
        foreach ($model['columns'] as $name => $c) {
            if ($c['src'] !== null) {
                $to[] = $w($name);
                $from[] = $w($c['src']);
            }
        }

        if ($to !== []) {
            $this->connection->statement(
                'insert into ' . $w($tmp) . ' (' . implode(', ', $to) . ') select ' . implode(', ', $from) . ' from ' . $w($table)
            );
        }

        $this->connection->statement($this->ddl->dropTable($table));
        $this->connection->statement($this->ddl->renameTable($tmp, $table));

        foreach ($model['indexes'] as $name => $index) {
            $this->connection->statement($this->ddl->createIndex($table, $name, $index));
        }
    }

    /** @param array<string, mixed> $m */
    private function apply(array $m, Operation $op): array
    {
        switch ($op->kind) {
            case 'addColumn':
                $m['columns'][$op->get('column')] = $op->get('def') + ['src' => null];
                break;

            case 'dropColumn':
                $col = $op->get('column');
                unset($m['columns'][$col]);
                $m['indexes'] = array_filter($m['indexes'], static fn (array $i) => !in_array($col, $i['columns'], true));
                $m['foreign'] = array_filter($m['foreign'], static fn (array $f) => $f['column'] !== $col);
                $m['primary'] = array_values(array_diff($m['primary'], [$col]));
                break;

            case 'changeColumn':
                $col = $op->get('column');
                $m['columns'][$col] = $op->get('to') + ['src' => $m['columns'][$col]['src'] ?? $col];
                break;

            case 'renameColumn':
                $from = $op->get('from');
                $to = $op->get('to');
                $renamed = [];
                foreach ($m['columns'] as $name => $c) {
                    $renamed[$name === $from ? $to : $name] = $c;
                }
                $m['columns'] = $renamed;
                $swap = static fn (array $cols) => array_map(static fn (string $c) => $c === $from ? $to : $c, $cols);
                foreach ($m['indexes'] as &$i) {
                    $i['columns'] = $swap($i['columns']);
                }
                unset($i);
                foreach ($m['foreign'] as &$f) {
                    $f['column'] = $f['column'] === $from ? $to : $f['column'];
                }
                unset($f);
                $m['primary'] = $swap($m['primary']);
                break;

            case 'addIndex':
                $m['indexes'][$op->get('name')] = $op->get('index');
                break;

            case 'dropIndex':
                unset($m['indexes'][$op->get('name')]);
                break;

            case 'addForeign':
                $m['foreign'][$op->get('name')] = $op->get('fk');
                break;

            case 'dropForeign':
                $sig = SchemaSnapshot::foreignSignature($op->get('fk'));
                $m['foreign'] = array_filter($m['foreign'], static fn (array $f) => SchemaSnapshot::foreignSignature($f) !== $sig);
                break;

            default:
                throw new DatabaseException("Пересборка не поддерживает операцию {$op->kind}.");
        }

        return $m;
    }
}
