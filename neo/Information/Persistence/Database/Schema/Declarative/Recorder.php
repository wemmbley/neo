<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Schema\Declarative;

use Closure;
use Neo\Information\Persistence\Database\Connection;
use Neo\Information\Persistence\Database\Exceptions\SchemaConflictException;
use Neo\Information\Persistence\Database\Migrations\Migration;
use Neo\Information\Persistence\Database\Schema\Blueprint;
use Neo\Information\Persistence\Database\Schema\ColumnDefinition;
use Neo\Information\Persistence\Database\Schema\SchemaGrammar;

/**
 * Режим записи. Пока он активен, Schema::create()/extend() ничего не выполняют, а складывают Blueprint сюда.
 * Результат: «вклад» файла (contribution), нормализованный снимок вида
 *
 *   ['tables' => [ table => ['mode' => 'create'|'extend', 'columns' => [...], 'indexes' => [...],
 *                            'foreign' => [...], 'primary' => [...]] ]]
 */
final class Recorder
{
    /** @var list<array<string, array{mode: string, blueprint: Blueprint}>> */
    private static array $stack = [];

    public static function active(): bool
    {
        return self::$stack !== [];
    }

    public static function add(string $mode, Blueprint $bp): void
    {
        $top = array_key_last(self::$stack);

        if (isset(self::$stack[$top][$bp->table])) {
            throw new SchemaConflictException("Таблица [{$bp->table}] объявлена в одном файле дважды.");
        }

        self::$stack[$top][$bp->table] = ['mode' => $mode, 'blueprint' => $bp];
    }

    /** @return array{tables: array<string, array<string, mixed>>} */
    public static function contribution(Migration $migration, Connection $connection): array
    {
        self::$stack[] = [];

        try {
            $migration->table();
        } finally {
            $recorded = array_pop(self::$stack);
        }

        $grammar = new SchemaGrammar($connection->driver(), $connection->grammar);
        $tables = [];

        foreach ($recorded as $table => $r) {
            $tables[$table] = self::tableSnapshot($r['mode'], $r['blueprint'], $grammar);
        }

        return ['tables' => $tables];
    }

    /** @return array<string, mixed> */
    private static function tableSnapshot(string $mode, Blueprint $bp, SchemaGrammar $grammar): array
    {
        $columns = $indexes = $foreign = $primary = [];

        foreach ($bp->columns as $c) {
            $columns[$c->name] = self::column($c, $grammar);

            if ($c->primary) {
                $primary[] = $c->name;
            }
            if ($c->unique && !$c->primary) {
                $indexes[$bp->table . '_' . $c->name . '_unique'] = ['type' => 'unique', 'columns' => [$c->name]];
            }
            if ($c->index) {
                $indexes[$bp->table . '_' . $c->name . '_index'] = ['type' => 'index', 'columns' => [$c->name]];
            }
            if ($c->on !== null) {
                $foreign[$c->fkName ?? $bp->table . '_' . $c->name . '_foreign'] = [
                    'column' => $c->name, 'on' => $c->on, 'references' => (string) $c->references,
                    'onDelete' => $c->onDelete, 'onUpdate' => $c->onUpdate,
                ];
            }
        }

        foreach ($bp->indexes as $i) {
            if ($i['type'] === 'primary') {
                $primary = $i['columns'];
                continue;
            }
            $indexes[$i['name'] ?? $bp->table . '_' . implode('_', $i['columns']) . '_' . $i['type']] = [
                'type' => $i['type'], 'columns' => array_values($i['columns']),
            ];
        }

        return [
            'mode' => $mode,
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign' => $foreign,
            'primary' => array_values(array_unique($primary)),
        ];
    }

    /** @return array<string, mixed> */
    private static function column(ColumnDefinition $c, SchemaGrammar $grammar): array
    {
        $def = [
            'type' => $c->type,
            'sql' => SchemaSnapshot::normalizeType($grammar->sqlType($c)),
            'nullable' => $c->nullable,
            'default' => SchemaSnapshot::normalizeDefault($grammar->defaultSql($c)),
            'auto' => $c->autoIncrement,
            'comment' => $c->comment,
        ];

        if ($c->renamedFrom !== null) {
            $def['renamedFrom'] = $c->renamedFrom;
        }

        return $def;
    }
}
