<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Console;

use Neo\Foundation\Console\Commands\Command;
use Neo\Information\Persistence\Database\Migrations\Migrator;
use Neo\Information\Persistence\Database\Schema\Declarative\SchemaMigrator;
use Throwable;

class MigrateStatusCommand extends Command
{
    protected string $signature = 'migrate:status';
    protected string $description = 'Показывает состояние legacy и декларативных миграций';

    private const STATES = ['applied' => '[Applied]  ', 'changed' => '[Changed]  ', 'new' => '[Pending]  '];

    public function __construct(
        private readonly Migrator $migrator,
        private readonly SchemaMigrator $schema,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $legacy = $this->migrator->status();
            $declarative = $this->schema->status();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->comment('Legacy (up/down):');
        foreach ($legacy as $row) {
            $this->line('  ' . ($row['ran'] ? '[Ran]      ' : '[Pending]  ') . $row['name']);
        }
        $legacy === [] && $this->line('  нет');

        $this->newLine();
        $this->comment('Декларативные (table):');
        foreach ($declarative['files'] as $f) {
            $this->line('  ' . self::STATES[$f['state']] . "{$f['owner']}::{$f['name']}" . ($f['commit'] ? "  (commit #{$f['commit']})" : ''));
        }
        $declarative['files'] === [] && $this->line('  нет');

        foreach ($declarative['orphans'] as $o) {
            $this->warn("  [Orphaned] {$o['owner']}::{$o['name']}  (commit #{$o['commit']}): файла нет, структура осталась. Удалить: migrate:purge");
        }

        return self::SUCCESS;
    }
}
