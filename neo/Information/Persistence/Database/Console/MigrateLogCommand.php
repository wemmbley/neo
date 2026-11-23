<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Console;

use Neo\Foundation\Console\Commands\Command;
use Neo\Information\Persistence\Database\Schema\Declarative\SchemaMigrator;
use Throwable;

class MigrateLogCommand extends Command
{
    protected string $signature = 'migrate:log {--file= : Только указанная миграция}';
    protected string $description = 'История коммитов декларативных миграций';

    public function __construct(private readonly SchemaMigrator $schema)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $log = $this->schema->log();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $file = $this->option('file');
        $shown = 0;

        foreach ($log as $c) {
            $key = $c['owner'] . '::' . $c['migration'];
            if ($file !== null && $file !== $c['migration'] && $file !== $key) {
                continue;
            }

            $shown++;
            $this->info("#{$c['id']}  {$c['created_at']}  {$c['action']}  {$key}");
            $c['operations'] === [] && $this->line('      без изменений структуры');
            foreach ($c['operations'] as $op) {
                $op->isDestructive() ? $this->warn('      ' . $op->describe()) : $this->line('      ' . $op->describe());
            }
        }

        $shown === 0 && $this->line('История пуста.');

        return self::SUCCESS;
    }
}
