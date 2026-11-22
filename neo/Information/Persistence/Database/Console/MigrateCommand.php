<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Console;

use Neo\Foundation\Console\Command;
use Neo\Information\Persistence\Database\Migrations\Migrator;

class MigrateCommand extends Command
{
    protected string $signature = 'migrate';
    protected string $description = 'Выполняет невыполненные миграции';

    public function __construct(private readonly Migrator $migrator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $done = $this->migrator->run(fn (string $n) => $this->info("Migrated: {$n}"));

        if ($done === []) {
            $this->info('Нечего мигрировать.');
        }

        return self::SUCCESS;
    }
}
