<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Schema;

use Closure;
use Neo\Information\Persistence\Database\Connection;

/**
 * `Schema::create('products', fn (Blueprint $t) => ...)`
 */
class SchemaBuilder
{
    private SchemaGrammar $schemaGrammar;

    public function __construct(private readonly Connection $connection)
    {
        $this->schemaGrammar = new SchemaGrammar($connection->driver(), $connection->grammar);
    }

    public function create(string $table, Closure $callback): void
    {
        $table = $this->connection->withPrefix($table);
        $bp = new Blueprint($table);
        $bp->creating = true;
        $callback($bp);

        $this->run($this->schemaGrammar->create($bp, $table));
    }

    public function table(string $table, Closure $callback): void
    {
        $table = $this->connection->withPrefix($table);
        $bp = new Blueprint($table);
        $callback($bp);

        $this->run($this->schemaGrammar->alter($bp, $table));
    }

    public function drop(string $table): void
    {
        $this->run([$this->schemaGrammar->drop($this->connection->withPrefix($table))]);
    }

    public function dropIfExists(string $table): void
    {
        $this->run([$this->schemaGrammar->dropIfExists($this->connection->withPrefix($table))]);
    }

    public function rename(string $from, string $to): void
    {
        $this->run([$this->schemaGrammar->rename(
            $this->connection->withPrefix($from),
            $this->connection->withPrefix($to),
        )]);
    }

    public function hasTable(string $table): bool
    {
        $table = $this->connection->withPrefix($table);

        $row = $this->connection->driver() === 'mysql'
            ? $this->connection->selectOne(
                'select table_name from information_schema.tables where table_schema = database() and table_name = ?',
                [$table],
            )
            : $this->connection->selectOne(
                "select name from sqlite_master where type = 'table' and name = ?",
                [$table],
            );

        return $row !== null;
    }

    /** @return list<string> */
    public function getColumnListing(string $table): array
    {
        $table = $this->connection->withPrefix($table);

        if ($this->connection->driver() === 'mysql') {
            $rows = $this->connection->select(
                'select column_name as name from information_schema.columns where table_schema = database() and table_name = ? order by ordinal_position',
                [$table],
            );
        } else {
            $rows = $this->connection->select(
                'pragma table_info(' . $this->connection->grammar->wrap($table) . ')'
            );
        }

        return array_map(static fn (array $r) => (string) ($r['name'] ?? $r['NAME'] ?? ''), $rows);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array(strtolower($column), array_map('strtolower', $this->getColumnListing($table)), true);
    }

    public function disableForeignKeyConstraints(): void
    {
        $this->connection->unprepared($this->connection->driver() === 'mysql'
            ? 'SET FOREIGN_KEY_CHECKS = 0'
            : 'PRAGMA foreign_keys = OFF');
    }

    public function enableForeignKeyConstraints(): void
    {
        $this->connection->unprepared($this->connection->driver() === 'mysql'
            ? 'SET FOREIGN_KEY_CHECKS = 1'
            : 'PRAGMA foreign_keys = ON');
    }

    /** @param list<string> $statements */
    private function run(array $statements): void
    {
        foreach ($statements as $sql) {
            $this->connection->statement($sql);
        }
    }
}
