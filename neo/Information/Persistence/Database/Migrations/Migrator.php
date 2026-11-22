<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Migrations;

use Closure;
use Neo\Information\Persistence\Database\DatabaseManager;
use Neo\Information\Persistence\Database\Exceptions\DatabaseException;

/**
 * Находит файлы миграций и выполняет up/down батчами.
 */
class Migrator
{
    /** @var list<string> */
    private array $paths;

    /** @param list<string> $paths */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly array $config,
        array $paths = [],
    ) {
        $this->paths = $paths;
    }

    public function addPath(string $path): void
    {
        $this->paths[] = $path;
    }

    private function repository(): MigrationRepository
    {
        $name = $this->config['connection'] ?? null;

        return new MigrationRepository($this->db->connection($name), $this->config['table'] ?? 'migrations');
    }

    /** @return array<string, string> имя => путь, по возрастанию имени */
    public function files(): array
    {
        $files = [];

        foreach ($this->paths as $dir) {
            foreach (glob(rtrim($dir, '/\\') . '/*.php') ?: [] as $file) {
                $files[basename($file, '.php')] = $file;
            }
        }

        ksort($files);

        return $files;
    }

    /** @return list<string> выполненные имена */
    public function run(?Closure $onMigrated = null): array
    {
        $repo = $this->repository();
        $repo->ensureTable();

        $pending = array_diff_key($this->files(), array_flip($repo->ran()));
        $batch = $repo->nextBatch();
        $done = [];

        foreach ($pending as $name => $file) {
            $this->resolve($file)->up();
            $repo->log($name, $batch);
            $done[] = $name;
            $onMigrated && $onMigrated($name);
        }

        return $done;
    }

    /** @return list<string> */
    public function rollback(?Closure $onRolledBack = null): array
    {
        $repo = $this->repository();
        $repo->ensureTable();
        $files = $this->files();
        $done = [];

        foreach ($repo->lastBatch() as $row) {
            $name = $row['migration'];

            if (!isset($files[$name])) {
                throw new DatabaseException("Файл миграции [{$name}] не найден.");
            }

            $this->resolve($files[$name])->down();
            $repo->forget($name);
            $done[] = $name;
            $onRolledBack && $onRolledBack($name);
        }

        return $done;
    }

    /** @return list<array{name: string, ran: bool}> */
    public function status(): array
    {
        $repo = $this->repository();
        $repo->ensureTable();
        $ran = array_flip($repo->ran());

        return array_map(
            static fn (string $name) => ['name' => $name, 'ran' => isset($ran[$name])],
            array_keys($this->files()),
        );
    }

    private function resolve(string $file): Migration
    {
        $migration = require $file;

        if (!$migration instanceof Migration) {
            throw new DatabaseException("Файл {$file} должен возвращать экземпляр " . Migration::class . '.');
        }

        return $migration;
    }
}
