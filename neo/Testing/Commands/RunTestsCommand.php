<?php

declare(strict_types=1);

namespace Neo\Testing\Commands;

use Neo\Console\Commands\Command;

class RunTestsCommand extends Command
{
    protected string $signature = 'test
        {path? : Путь к файлу теста}
        {--filter= : Фильтр по имени теста}';

    protected string $description = 'Запуск тестов движка';

    public bool $disableMounting = true;

    public function handle(): int
    {
        $config = require base_path('autoload.php');

        $argv = ['test'];

        $path = $this->argument('path');
        $filter = $this->option('filter');

        if ($path !== null) {
            $argv[] = $path;
        }

        if ($filter !== null) {
            $argv[] = "--filter={$filter}";
        }

        return \Neo\Testing\Runner::main(
            $argv,
            $config['tests_paths']
        );
    }
}