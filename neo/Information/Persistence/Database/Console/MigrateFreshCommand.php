<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Console;

use Neo\Foundation\Console\Command;
use Neo\Information\Persistence\Database\DatabaseManager;
use Neo\Information\Persistence\Database\Migrations\Migrator;

class MigrateFreshCommand extends Command
{
    protected string $signature = 'migrate:fresh {--seed}';
    protected string $description = 'Удаляет все таблицы и выполняет миграции заново';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Migrator $migrator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $conn = $this->db->connection();
        $schema = $conn->schema();

        $schema->disableForeignKeyConstraints();

        $tables = $conn->driver() === 'mysql'
            ? array_map(static fn (array $r) => reset($r), $conn->select('show tables'))
            : array_column($conn->select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"), 'name');

        foreach ($tables as $table) {
            $schema->drop((string) $table);
        }

        $schema->enableForeignKeyConstraints();

        $this->migrator->run(fn (string $n) => $this->info("Migrated: {$n}"));

        if ($this->option('seed')) {
            return (int) app(DbSeedCommand::class)->handle();
        }

        return self::SUCCESS;
    }
}
