<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Console;

use Neo\Foundation\Console\Commands\Command;
use Neo\Information\Persistence\Database\Console\Concerns\HandlesSchemaPlans;
use Neo\Information\Persistence\Database\Schema\Declarative\SchemaMigrator;
use Throwable;

class MigratePurgeCommand extends Command
{
    use HandlesSchemaPlans;

    protected string $signature = 'migrate:purge {--pretend} {--yes}';
    protected string $description = 'Удаляет из БД колонки и таблицы миграций, файлов которых больше нет (орфанов)';

    public function __construct(private readonly SchemaMigrator $schema)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $plan = $this->schema->purgePlan();
        } catch (Throwable $e) {
            return $this->fail($e);
        }

        if ($plan->isEmpty()) {
            $this->info('Орфанов нет.');

            return self::SUCCESS;
        }

        $this->printPlan($plan);

        if ($this->option('pretend')) {
            $this->printPretend($this->schema, $plan);

            return self::SUCCESS;
        }

        return $this->applyPlan($this->schema, $plan, (bool) $this->option('yes'));
    }
}
