<?php

declare(strict_types=1);

namespace Neo\Diagnostic\Benchmark\Console;

use Neo\Diagnostic\Benchmark\Scenarios\Registry;
use Neo\Diagnostic\Benchmark\Support\Context;
use Neo\Diagnostic\Benchmark\Support\NoDataException;
use Neo\Diagnostic\Benchmark\Support\Runner;
use Neo\Foundation\Config\Config;
use Neo\Foundation\Console\Commands\Command;
use Neo\Information\Persistence\Database\DatabaseManager;

class BenchmarkRunCommand extends Command
{
    protected string $signature = 'benchmark:run {--n=100 : Итераций на сценарий} {--warmup=5 : Прогрев} {--group= : Группы через запятую: plain, db.read, db.write, mixed} {--only= : Имена сценариев через запятую}';

    protected string $description = 'Прогоняет сценарии в процессе консоли (без HTTP) и печатает таблицу задержек';

    public function __construct(
        private readonly Registry $registry,
        private readonly Runner $runner,
        private readonly DatabaseManager $db,
        private readonly Config $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $n = max(1, (int) $this->option('n'));
        $warmup = max(0, (int) $this->option('warmup'));
        $context = new Context($this->db, [], (int) $this->config->get('benchmark.bounds_ttl', 5));
        $rows = [];

        try {
            foreach ($this->registry->select($this->csv('group'), $this->csv('only')) as $scenario) {
                $iterations = $scenario->heavy ? max(3, intdiv($n, 10)) : $n;
                $r = $this->runner->measure($scenario, $context, $iterations, $warmup);
                unset($r['_result']);
                $rows[] = $r;
                $this->line('  ' . $scenario->name . ' ... ' . $r['avg'] . ' мс');
            }
        } catch (NoDataException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line(Runner::table($rows));

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function csv(string $option): array
    {
        $value = (string) ($this->option($option) ?? '');

        return $value === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
