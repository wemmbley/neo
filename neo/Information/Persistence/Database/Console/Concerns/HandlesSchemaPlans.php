<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Console\Concerns;

use Closure;
use Neo\Information\Persistence\Database\Exceptions\DatabaseException;
use Neo\Information\Persistence\Database\Schema\Declarative\Operation;
use Neo\Information\Persistence\Database\Schema\Declarative\SchemaMigrator;
use Neo\Information\Persistence\Database\Schema\Declarative\SchemaPlan;
use Throwable;

/**
 * Общее для команд, работающих с планом декларативных миграций: вывод плана,
 * вопросы пользователю и подтверждение разрушающих операций.
 */
trait HandlesSchemaPlans
{
    protected function interactive(): bool
    {
        return defined('STDIN') && stream_isatty(STDIN);
    }

    protected function ask(string $question): bool
    {
        fwrite(STDOUT, $question . ' [y/N] ');
        $answer = fgets(STDIN);

        return in_array(mb_strtolower(trim((string) $answer)), ['y', 'yes', 'д', 'да'], true);
    }

    /** Вопрос о переименовании колонки; null вне терминала (тогда неоднозначность отклоняется). */
    protected function renameResolver(): ?Closure
    {
        return $this->interactive()
            ? fn (string $table, string $old, string $new): bool => $this->ask("Таблица {$table}: колонка «{$old}» исчезла, «{$new}» появилась. Это переименование (данные сохранятся)?")
            : null;
    }

    protected function printPlan(SchemaPlan $plan): void
    {
        foreach ($plan->warnings as $warning) {
            $this->warn(' ! ' . $warning);
        }

        foreach ($plan->items as $item) {
            $this->line("{$item->key()}  [{$item->label}]");

            if ($item->operations === []) {
                $this->comment('    структура не меняется');
            }

            foreach ($item->operations as $op) {
                $text = '    ' . $op->describe();
                $op->isDestructive() ? $this->warn($text) : $this->line($text);
            }
        }
    }

    /** Показывает SQL, ничего не выполняя. */
    protected function printPretend(SchemaMigrator $migrator, SchemaPlan $plan): void
    {
        $this->newLine();
        $this->comment('SQL (--pretend, ничего не выполнено):');

        foreach ($migrator->pretend($plan) as $line) {
            $this->line($line);
        }
    }

    /**
     * Применяет план с подтверждением разрушающих операций.
     *
     * @return int код выхода
     */
    protected function applyPlan(SchemaMigrator $migrator, SchemaPlan $plan, bool $yes): int
    {
        $confirm = $this->interactive()
            ? function (array $destructive): bool {
                $this->newLine();
                $this->warn(' ВНИМАНИЕ: операции ниже необратимо затрагивают данные или структуру ');
                foreach ($destructive as $op) {
                    /** @var Operation $op */
                    $this->warn('   ' . $op->describe());
                }

                return $this->ask('Продолжить?');
            }
            : null;

        try {
            $ok = $migrator->execute($plan, $yes, $confirm, fn (string $line) => $this->info('Commit ' . $line));
        } catch (Throwable $e) {
            return $this->fail($e);
        }

        if (!$ok) {
            $this->comment('Отменено, ничего не изменено.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function fail(Throwable $e): int
    {
        $this->error($e instanceof DatabaseException || $e instanceof \LogicException ? $e->getMessage() : $e::class . ': ' . $e->getMessage());

        return self::FAILURE;
    }
}
