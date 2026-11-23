<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Console;

use Neo\Foundation\Console\Commands\Command;
use Neo\Information\Persistence\Database\Migrations\Migrator;

class MigrateRollbackCommand extends Command
{
    protected string $signature = 'migrate:rollback';
    protected string $description = 'Откатывает последний батч миграций';

    public function __construct(private readonly Migrator $migrator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $done = $this->migrator->rollback(fn (string $n) => $this->info("Rolled back: {$n}"));

        if ($done === []) {
            $this->info('Нечего откатывать.');
        }

        return self::SUCCESS;
    }
}
