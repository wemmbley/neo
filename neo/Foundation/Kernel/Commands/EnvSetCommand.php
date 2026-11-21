<?php

declare(strict_types=1);

namespace Neo\Foundation\Kernel\Commands;

use Neo\Foundation\Config\Env;
use Neo\Foundation\Console\Commands\Command;
use Neo\Foundation\Kernel\Kernel;

class EnvSetCommand extends Command
{
    protected string $signature = 'env:set {key : Имя переменной} {value : Значение}';

    protected string $description = 'Записывает КЛЮЧ=значение в файл .env';

    public bool $disableMounting = true;

    public function __construct(private readonly Kernel $kernel)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = $this->kernel->envPath() ?? $this->kernel->basePath('.env');

        try {
            Env::writeToFile($path, $this->argument('key'), $this->argument('value'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($this->argument('key') . ' записан в ' . $path);

        return self::SUCCESS;
    }
}
