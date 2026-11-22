<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Migrations;

use Neo\Information\Persistence\Database\Connection;
use Neo\Information\Persistence\Database\Schema\Blueprint;

/**
 * Журнал выполненных миграций (таблица `migrations`).
 */
class MigrationRepository
{
    public function __construct(private readonly Connection $connection, private readonly string $table = 'migrations') {}

    public function ensureTable(): void
    {
        $schema = $this->connection->schema();

        if ($schema->hasTable($this->table)) {
            return;
        }

        $schema->create($this->table, function (Blueprint $t): void {
            $t->increments('id');
            $t->string('migration');
            $t->integer('batch');
        });
    }

    /** @return list<string> */
    public function ran(): array
    {
        return array_values($this->query()->orderBy('batch')->orderBy('id')->pluck('migration'));
    }

    /** @return list<array{id: int, migration: string, batch: int}> последний батч в обратном порядке */
    public function lastBatch(): array
    {
        $batch = (int) $this->query()->max('batch');

        return $this->query()->where('batch', '=', $batch)->orderByDesc('id')->get();
    }

    public function nextBatch(): int
    {
        return (int) $this->query()->max('batch') + 1;
    }

    public function log(string $migration, int $batch): void
    {
        $this->query()->insert(['migration' => $migration, 'batch' => $batch]);
    }

    public function forget(string $migration): void
    {
        $this->query()->where('migration', '=', $migration)->delete();
    }

    private function query(): \Neo\Information\Persistence\Database\Query\Builder
    {
        return $this->connection->table($this->table);
    }
}
