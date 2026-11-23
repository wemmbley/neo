<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Console;

use Neo\Foundation\Console\Commands\Command;

class MakeMigrationCommand extends Command
{
    protected string $signature = 'make:migration {name} {--create=} {--table=}';
    protected string $description = 'Создаёт файл миграции';

    public function handle(): int
    {
        $name = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', (string) $this->argument('name')));
        $dir = (string) (config('database.migrations.paths')[0] ?? base_path('app/Database/Migrations'));
        is_dir($dir) || mkdir($dir, 0775, true);

        $file = $dir . '/' . date('Y_m_d_His') . '_' . $name . '.php';

        $create = $this->option('create');
        $table = $this->option('table');

        $up = $create
            ? "Schema::create('{$create}', function (Blueprint \$table): void {\n            \$table->id();\n            \$table->timestamps();\n        });"
            : ($table ? "Schema::table('{$table}', function (Blueprint \$table): void {\n            //\n        });" : '//');
        $down = $create ? "Schema::dropIfExists('{$create}');" : '//';

        file_put_contents($file, <<<PHP
<?php

declare(strict_types=1);

use Neo\\Information\\Persistence\\Database\\Facades\\Schema;
use Neo\\Information\\Persistence\\Database\\Migrations\\Migration;
use Neo\\Information\\Persistence\\Database\\Schema\\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        {$up}
    }

    public function down(): void
    {
        {$down}
    }
};

PHP);

        $this->info("Created: {$file}");

        return self::SUCCESS;
    }
}
