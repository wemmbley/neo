<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Schema\Declarative;

use Neo\Information\Persistence\Database\Connection;

/**
 * Читает реальную структуру таблицы (sqlite: PRAGMA, mysql: information_schema)
 * и возвращает её в том же формате, что и снимок вклада:
 * columns / indexes / foreign / primary. Имя таблицы передаётся уже с префиксом.
 */
final class Introspector
{
    public function __construct(private readonly Connection $connection) {}

    private function mysql(): bool
    {
        return $this->connection->driver() === 'mysql';
    }

    public function exists(string $table): bool
    {
        $row = $this->mysql()
            ? $this->connection->selectOne(
                'select table_name as n from information_schema.tables where table_schema = database() and table_name = ?',
                [$table],
            )
            : $this->connection->selectOne("select name as n from sqlite_master where type = 'table' and name = ?", [$table]);

        return $row !== null;
    }

    /** @return array{columns: array<string, array>, indexes: array<string, array>, foreign: array<string, array>, primary: list<string>} */
    public function table(string $table): array
    {
        return $this->mysql() ? $this->mysqlTable($table) : $this->sqliteTable($table);
    }

    private function sqliteTable(string $table): array
    {
        $w = $this->connection->grammar->wrap(...);
        $info = $this->connection->select('pragma table_info(' . $w($table) . ')');
        $sqlRow = $this->connection->selectOne("select sql from sqlite_master where type = 'table' and name = ?", [$table]);
        $hasAutoincrement = stripos((string) ($sqlRow['sql'] ?? ''), 'autoincrement') !== false;

        $pk = [];
        foreach ($info as $r) {
            if ((int) $r['pk'] > 0) {
                $pk[(int) $r['pk']] = (string) $r['name'];
            }
        }
        ksort($pk);
        $primary = array_values($pk);

        $columns = [];
        foreach ($info as $r) {
            $name = (string) $r['name'];
            $columns[$name] = [
                'type' => null,
                'sql' => SchemaSnapshot::normalizeType((string) $r['type']),
                'nullable' => !(bool) $r['notnull'],
                'default' => SchemaSnapshot::normalizeDefault($r['dflt_value'] === null ? null : (string) $r['dflt_value']),
                'auto' => $hasAutoincrement && count($primary) === 1 && $primary[0] === $name,
                'comment' => null,
            ];
        }

        $indexes = [];
        foreach ($this->connection->select('pragma index_list(' . $w($table) . ')') as $i) {
            if (($i['origin'] ?? 'c') === 'pk') {
                continue;
            }

            $cols = $this->connection->select('pragma index_info(' . $w((string) $i['name']) . ')');
            usort($cols, static fn (array $a, array $b) => (int) $a['seqno'] <=> (int) $b['seqno']);
            $names = array_map(static fn (array $c) => (string) $c['name'], $cols);
            $type = (int) $i['unique'] === 1 ? 'unique' : 'index';

            $name = str_starts_with((string) $i['name'], 'sqlite_autoindex')
                ? $table . '_' . implode('_', $names) . '_unique'
                : (string) $i['name'];

            $indexes[$name] = ['type' => $type, 'columns' => $names];
        }

        $foreign = [];
        foreach ($this->connection->select('pragma foreign_key_list(' . $w($table) . ')') as $f) {
            $foreign[$table . '_' . $f['from'] . '_foreign'] = [
                'column' => (string) $f['from'],
                'on' => (string) $f['table'],
                'references' => (string) ($f['to'] ?? 'id'),
                'onDelete' => self::rule((string) $f['on_delete']),
                'onUpdate' => self::rule((string) $f['on_update']),
            ];
        }

        return compact('columns', 'indexes', 'foreign', 'primary');
    }

    private function mysqlTable(string $table): array
    {
        $rows = $this->connection->select(
            'select column_name as name, column_type as type, is_nullable as nullable, column_default as dflt, extra as extra, column_comment as comment'
            . ' from information_schema.columns where table_schema = database() and table_name = ? order by ordinal_position',
            [$table],
        );

        $columns = [];
        foreach ($rows as $r) {
            $type = SchemaSnapshot::normalizeType((string) $r['type']);
            $columns[(string) $r['name']] = [
                'type' => null,
                'sql' => $type,
                'nullable' => strtoupper((string) $r['nullable']) === 'YES',
                'default' => $this->mysqlDefault($r['dflt'], $type),
                'auto' => str_contains(strtolower((string) $r['extra']), 'auto_increment'),
                'comment' => ($r['comment'] ?? '') === '' ? null : (string) $r['comment'],
            ];
        }

        $primary = [];
        $grouped = [];
        $stats = $this->connection->select(
            'select index_name as name, non_unique as non_unique, column_name as col, seq_in_index as seq'
            . ' from information_schema.statistics where table_schema = database() and table_name = ? order by index_name, seq_in_index',
            [$table],
        );
        foreach ($stats as $s) {
            if ($s['name'] === 'PRIMARY') {
                $primary[] = (string) $s['col'];
                continue;
            }
            $grouped[(string) $s['name']]['type'] = (int) $s['non_unique'] === 0 ? 'unique' : 'index';
            $grouped[(string) $s['name']]['columns'][] = (string) $s['col'];
        }

        $rules = [];
        foreach ($this->connection->select(
            'select constraint_name as name, delete_rule as del, update_rule as upd from information_schema.referential_constraints'
            . ' where constraint_schema = database() and table_name = ?',
            [$table],
        ) as $r) {
            $rules[(string) $r['name']] = [self::rule((string) $r['del']), self::rule((string) $r['upd'])];
        }

        $foreign = [];
        foreach ($this->connection->select(
            'select constraint_name as name, column_name as col, referenced_table_name as ref_table, referenced_column_name as ref_col'
            . ' from information_schema.key_column_usage where table_schema = database() and table_name = ? and referenced_table_name is not null',
            [$table],
        ) as $f) {
            $foreign[(string) $f['name']] = [
                'column' => (string) $f['col'],
                'on' => (string) $f['ref_table'],
                'references' => (string) $f['ref_col'],
                'onDelete' => $rules[(string) $f['name']][0] ?? null,
                'onUpdate' => $rules[(string) $f['name']][1] ?? null,
            ];
        }

        return ['columns' => $columns, 'indexes' => $grouped, 'foreign' => $foreign, 'primary' => $primary];
    }

    /** information_schema отдаёт default без кавычек: вернуть SQL-литерал, как в объявлении. */
    private function mysqlDefault(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        if (preg_match('/^current_timestamp(\(\d*\))?$/i', $value)) {
            return 'current_timestamp';
        }

        if (preg_match('/^(varchar|char|tinytext|text|mediumtext|longtext|enum|set|date|datetime|timestamp|time|json)/', $type)) {
            return "'" . str_replace("'", "''", $value) . "'";
        }

        return SchemaSnapshot::normalizeDefault($value);
    }

    private static function rule(string $rule): ?string
    {
        return match (strtolower(trim($rule))) {
            'cascade' => 'cascade',
            'set null' => 'set null',
            'set default' => 'set default',
            default => null,
        };
    }
}
