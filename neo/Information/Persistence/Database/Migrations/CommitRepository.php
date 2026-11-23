<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Migrations;

use Neo\Information\Persistence\Database\Connection;
use Neo\Information\Persistence\Database\Schema\Blueprint;

/**
 * История декларативных миграций (таблица `migrations_commits`). Одна строка: один коммит,
 * то есть изменение вклада одного файла. id сквозной. Снимок и операции хранятся в JSON.
 */
class CommitRepository
{
    public function __construct(private readonly Connection $connection, private readonly string $table = 'migrations_commits') {}

    public function ensureTable(): void
    {
        $schema = $this->connection->schema();

        if ($schema->hasTable($this->table)) {
            return;
        }

        $schema->create($this->table, function (Blueprint $t): void {
            $t->increments('id');
            $t->string('owner', 191);
            $t->string('migration', 191);
            $t->string('action', 16);
            $t->string('hash', 64);
            $t->text('snapshot');
            $t->text('operations');
            $t->string('created_at', 32);
        });
    }

    /** @return list<array{id: int, owner: string, migration: string, action: string, hash: string, snapshot: array, operations: list<array>, created_at: string}> */
    public function all(): array
    {
        if (!$this->connection->schema()->hasTable($this->table)) {
            return [];
        }

        $rows = $this->connection->table($this->table)->orderBy('id')->get();

        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'owner' => (string) $r['owner'],
            'migration' => (string) $r['migration'],
            'action' => (string) $r['action'],
            'hash' => (string) $r['hash'],
            'snapshot' => json_decode((string) $r['snapshot'], true, 512, JSON_THROW_ON_ERROR),
            'operations' => json_decode((string) $r['operations'], true, 512, JSON_THROW_ON_ERROR),
            'created_at' => (string) $r['created_at'],
        ], $rows);
    }

    /** Последний коммит каждого файла: ключ `owner::migration`. */
    public function heads(): array
    {
        $heads = [];

        foreach ($this->all() as $row) {
            $heads[$row['owner'] . '::' . $row['migration']] = $row;
        }

        return $heads;
    }

    /** @param list<array> $operations */
    public function record(string $owner, string $migration, string $action, string $hash, array $snapshot, array $operations): int
    {
        return (int) $this->connection->table($this->table)->insertGetId([
            'owner' => $owner,
            'migration' => $migration,
            'action' => $action,
            'hash' => $hash,
            'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'operations' => json_encode($operations, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
