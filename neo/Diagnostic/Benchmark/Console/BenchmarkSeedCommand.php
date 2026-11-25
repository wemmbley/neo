<?php

declare(strict_types=1);

namespace Neo\Diagnostic\Benchmark\Console;

use Neo\Diagnostic\Benchmark\Support\Seeder;
use Neo\Foundation\Console\Commands\Command;

class BenchmarkSeedCommand extends Command
{
    protected string $signature = 'benchmark:seed {--users=1000 : Пользователей} {--posts=10000 : Постов} {--comments=50000 : Комментариев} {--fresh : Сначала очистить таблицы}';

    protected string $description = 'Наполняет таблицы bench_* случайными данными';

    public function __construct(private readonly Seeder $seeder)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $this->seeder->reset();
            $this->info('Таблицы bench_* очищены.');
        }

        $report = $this->seeder->seed((int) $this->option('users'), (int) $this->option('posts'), (int) $this->option('comments'));

        foreach ($report as $table => $r) {
            $this->line(sprintf('  %-16s %8d строк  %10.1f мс', $table, $r['rows'], $r['ms']));
        }

        foreach ($this->seeder->counts() as $table => $count) {
            $this->line(sprintf('  в таблице %-16s %d', $table, $count));
        }

        return self::SUCCESS;
    }
}
