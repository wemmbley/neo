<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Migrations;

use Closure;
use Neo\Information\Persistence\Database\Connection;
use Neo\Information\Persistence\Database\DatabaseManager;
use Neo\Information\Persistence\Database\Exceptions\DatabaseException;
use Neo\Information\Persistence\Database\Exceptions\DoubleStrategyException;
use Neo\Information\Persistence\Database\Exceptions\MigrationStrategyException;
use ReflectionMethod;

/**
 * Находит файлы миграций. Legacy (up/down) выполняет батчами;
 * декларативные (table) отдаёт {@see \Neo\Information\Persistence\Database\Schema\Declarative\SchemaMigrator}.
 */
class Migrator
{
    public const LEGACY = 'legacy';
    public const DECLARATIVE = 'declarative';

    /** @var list<array{path: string, owner: string}> */
    private array $paths = [];

    /** @var array<string, Migration> */
    private array $resolved = [];

    /** @param list<string> $paths */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly array $config,
        array $paths = [],
    ) {
        foreach ($paths as $path) {
            $this->addPath($path);
        }
    }

    /** @var (Closure(): list<string>)|null отложенные пути модулей (провайдеры регистрируются позже Migrator) */
    private ?Closure $pathResolver = null;

    /** @param Closure(): list<string> $resolver */
    public function resolvePathsUsing(Closure $resolver): void
    {
        $this->pathResolver = $resolver;
    }

    /** @return list<array{path: string, owner: string}> */
    private function allPaths(): array
    {
        $paths = $this->paths;
        $known = array_column($paths, 'path');

        foreach ($this->pathResolver ? ($this->pathResolver)() : [] as $path) {
            if (!in_array($path, $known, true)) {
                $paths[] = ['path' => $path, 'owner' => 'module'];
            }
        }

        return $paths;
    }

    /** @param string $owner владелец путей: класс провайдера модуля, 'app' для приложения */
    public function addPath(string $path, string $owner = 'app'): void
    {
        $this->paths[] = ['path' => $path, 'owner' => $owner];
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function connection(): Connection
    {
        return $this->db->connection($this->config['connection'] ?? null);
    }

    private function repository(): MigrationRepository
    {
        return new MigrationRepository($this->connection(), $this->config['table'] ?? 'migrations');
    }

    /**
     * Все файлы с определённой стратегией, по возрастанию имени.
     *
     * @return list<array{owner: string, name: string, file: string, strategy: string, migration: Migration}>
     */
    public function entries(): array
    {
        $entries = [];

        foreach ($this->allPaths() as ['path' => $dir, 'owner' => $owner]) {
            foreach (glob(rtrim($dir, '/\\') . '/*.php') ?: [] as $file) {
                $migration = $this->resolve($file);

                $entries[] = [
                    'owner' => $owner,
                    'name' => basename($file, '.php'),
                    'file' => $file,
                    'strategy' => $this->strategy($migration, $file),
                    'migration' => $migration,
                ];
            }
        }

        usort($entries, static fn (array $a, array $b) => [$a['name'], $a['owner']] <=> [$b['name'], $b['owner']]);

        return $entries;
    }

    /** @return list<array{owner: string, name: string, file: string, strategy: string, migration: Migration}> */
    public function declarative(): array
    {
        return array_values(array_filter($this->entries(), static fn (array $e) => $e['strategy'] === self::DECLARATIVE));
    }

    /** @return array<string, string> имя => путь legacy-миграций */
    public function files(): array
    {
        $files = [];

        foreach ($this->entries() as $e) {
            if ($e['strategy'] === self::LEGACY) {
                $files[$e['name']] = $e['file'];
            }
        }

        return $files;
    }

    /** @return list<string> выполненные имена */
    public function run(?Closure $onMigrated = null): array
    {
        $files = $this->files();
        $repo = $this->repository();
        $repo->ensureTable();

        $pending = array_diff_key($files, array_flip($repo->ran()));
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

    /** @return list<string> имена legacy-миграций, которые ещё не выполнены */
    public function pending(): array
    {
        $files = $this->files();
        $repo = $this->repository();
        $ran = $this->db->connection($this->config['connection'] ?? null)->schema()->hasTable($this->config['table'] ?? 'migrations')
            ? $repo->ran()
            : [];

        return array_keys(array_diff_key($files, array_flip($ran)));
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

    /** @return list<array{name: string, ran: bool}> только legacy */
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

    /** Файл выполняется один раз за процесс: повторный require анонимного класса был бы лишней работой. */
    public function resolve(string $file): Migration
    {
        if (isset($this->resolved[$file])) {
            return $this->resolved[$file];
        }

        $migration = require $file;

        if (!$migration instanceof Migration) {
            throw new DatabaseException("Файл {$file} должен возвращать экземпляр " . Migration::class . '.');
        }

        return $this->resolved[$file] = $migration;
    }

    private function strategy(Migration $migration, string $file): string
    {
        $overrides = static fn (string $method): bool
        => (new ReflectionMethod($migration, $method))->getDeclaringClass()->getName() !== Migration::class;

        $legacy = $overrides('up');
        $declarative = $overrides('table');

        return match (true) {
            $legacy && $declarative => throw new DoubleStrategyException($file),
            $legacy => self::LEGACY,
            $declarative => self::DECLARATIVE,
            default => throw new MigrationStrategyException($file),
        };
    }
}
