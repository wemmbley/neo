<?php

declare(strict_types=1);

namespace Neo\Foundation\Testing\Commands;

use Neo\Foundation\Config\Config;
use Neo\Foundation\Console\Commands\Command;
use Neo\Foundation\Testing\Runner;

class RunTestsCommand extends Command
{
    protected string $signature = 'test
        {path? : Путь к файлу теста}
        {--filter= : Фильтр по имени теста}';

    protected string $description = 'Запуск тестов движка';

    public bool $disableMounting = true;

    public function __construct(private readonly Config $config)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $paths = array_values(array_filter(
            (array) $this->config->get('console.tests', []),
            'is_string',
        ));

        if ($paths === []) {
            $this->error('Не заданы папки с тестами: console.tests в app/Config/console.php.');

            return self::FAILURE;
        }

        $argv = ['test'];

        $path = $this->argument('path');
        $filter = $this->option('filter');

        if ($path !== null) {
            $argv[] = $path;
        }

        if ($filter !== null) {
            $argv[] = "--filter={$filter}";
        }

        return Runner::main($argv, $paths);
    }
}