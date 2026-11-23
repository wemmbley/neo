<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Console;

use Neo\Foundation\Console\Commands\Command;
use Neo\Information\Persistence\Database\Seeding\Seeder;

class DbSeedCommand extends Command
{
    protected string $signature = 'db:seed {--class=}';
    protected string $description = 'Запускает сидер (по умолчанию database.seeder)';

    public function handle(): int
    {
        $class = $this->option('class') ?: config('database.seeder');

        if (!is_string($class) || !class_exists($class)) {
            $this->error("Сидер не найден: " . var_export($class, true));

            return self::FAILURE;
        }

        /** @var Seeder $seeder */
        $seeder = kernel($class);
        $seeder->run();

        $this->info("Seeded: {$class}");

        return self::SUCCESS;
    }
}
