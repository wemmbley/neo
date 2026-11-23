<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Console;

use Neo\Foundation\Console\Commands\Command;

class MakeSeederCommand extends Command
{
    protected string $signature = 'make:seeder {name}';
    protected string $description = 'Создаёт класс сидера в app/Database/Seeders';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $dir = base_path('app/Database/Seeders');
        is_dir($dir) || mkdir($dir, 0775, true);
        $file = "{$dir}/{$name}.php";

        $configured = (string) config('database.seeder', 'Database\\Seeders\\DatabaseSeeder');
        $namespace = str_contains($configured, '\\') ? substr($configured, 0, (int) strrpos($configured, '\\')) : 'Database\\Seeders';

        file_put_contents($file, <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Neo\\Information\\Persistence\\Database\\Seeding\\Seeder;

class {$name} extends Seeder
{
    public function run(): void
    {
        //
    }
}

PHP);

        $this->info("Created: {$file}");

        return self::SUCCESS;
    }
}
