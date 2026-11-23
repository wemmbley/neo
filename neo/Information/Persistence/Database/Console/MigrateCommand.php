<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Console;

use Neo\Foundation\Console\Commands\Command;
use Neo\Information\Persistence\Database\Console\Concerns\HandlesSchemaPlans;
use Neo\Information\Persistence\Database\Migrations\Migrator;
use Neo\Information\Persistence\Database\Schema\Declarative\SchemaMigrator;
use Throwable;

class MigrateCommand extends Command
{
    use HandlesSchemaPlans;

    protected string $signature = 'migrate {--pretend : Показать план и SQL, ничего не выполняя} {--yes : Подтвердить разрушающие операции заранее}';
    protected string $description = 'Выполняет миграции: сначала legacy (up), затем декларативные (table)';

    public function __construct(
        private readonly Migrator $migrator,
        private readonly SchemaMigrator $schema,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            if ($this->option('pretend')) {
                return $this->pretend();
            }

            $done = $this->migrator->run(fn (string $n) => $this->info("Migrated: {$n}"));
            $plan = $this->schema->plan($this->renameResolver());
        } catch (Throwable $e) {
            return $this->fail($e);
        }

        if ($done === [] && $plan->isEmpty()) {
            $this->info('Нечего мигрировать.');

            return self::SUCCESS;
        }

        if ($plan->isEmpty()) {
            return self::SUCCESS;
        }

        $this->printPlan($plan);

        return $this->applyPlan($this->schema, $plan, (bool) $this->option('yes'));
    }

    private function pretend(): int
    {
        try {
            $pending = $this->migrator->pending();
            $plan = $this->schema->plan($this->renameResolver());
        } catch (Throwable $e) {
            return $this->fail($e);
        }

        foreach ($pending as $name) {
            $this->line("Legacy: {$name} (будет выполнен up())");
        }

        if ($plan->isEmpty() && $pending === []) {
            $this->info('Нечего мигрировать.');

            return self::SUCCESS;
        }

        $this->printPlan($plan);
        $plan->isEmpty() || $this->printPretend($this->schema, $plan);

        return self::SUCCESS;
    }
}
