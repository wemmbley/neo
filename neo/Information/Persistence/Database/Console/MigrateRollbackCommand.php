<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Console;

use Neo\Foundation\Console\Commands\Command;
use Neo\Information\Persistence\Database\Console\Concerns\HandlesSchemaPlans;
use Neo\Information\Persistence\Database\Migrations\Migrator;
use Neo\Information\Persistence\Database\Schema\Declarative\SchemaMigrator;
use Throwable;

class MigrateRollbackCommand extends Command
{
    use HandlesSchemaPlans;

    protected string $signature = 'migrate:rollback {--to= : Вернуть структуру к состоянию после коммита ID (декларативные)} {--file= : Ограничить --to одной миграцией} {--pretend} {--yes}';
    protected string $description = 'Без параметров: откат последнего legacy-батча. С --to=ID: возврат декларативной схемы к коммиту';

    public function __construct(
        private readonly Migrator $migrator,
        private readonly SchemaMigrator $schema,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $to = $this->option('to');

        if ($to === null) {
            if ($this->option('file') !== null) {
                $this->error('--file работает только вместе с --to=ID.');

                return self::INVALID;
            }

            try {
                $done = $this->migrator->rollback(fn (string $n) => $this->info("Rolled back: {$n}"));
            } catch (Throwable $e) {
                return $this->fail($e);
            }

            $done === [] && $this->info('Нечего откатывать.');

            return self::SUCCESS;
        }

        if (!ctype_digit((string) $to)) {
            $this->error('--to ожидает номер коммита (см. migrate:log).');

            return self::INVALID;
        }

        try {
            $plan = $this->schema->rollbackPlan((int) $to, $this->option('file'));
        } catch (Throwable $e) {
            return $this->fail($e);
        }

        if ($plan->isEmpty()) {
            $this->info("После коммита #{$to} изменений нет.");

            return self::SUCCESS;
        }

        $this->printPlan($plan);
        $this->comment('Откат возвращает только структуру: данные удалённых колонок и таблиц не восстанавливаются.');

        if ($this->option('pretend')) {
            $this->printPretend($this->schema, $plan);

            return self::SUCCESS;
        }

        return $this->applyPlan($this->schema, $plan, (bool) $this->option('yes'));
    }
}
