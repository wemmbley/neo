<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Console;

use Neo\Foundation\Console\Commands\Command;
use Neo\Information\Persistence\Database\Migrations\Migrator;

class MigrateStatusCommand extends Command
{
    protected string $signature = 'migrate:status';
    protected string $description = 'Показывает состояние миграций';

    public function __construct(private readonly Migrator $migrator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        foreach ($this->migrator->status() as $row) {
            $this->line(($row['ran'] ? '[Ran]     ' : '[Pending] ') . $row['name']);
        }

        return self::SUCCESS;
    }
}
