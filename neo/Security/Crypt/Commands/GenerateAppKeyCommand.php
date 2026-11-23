<?php

declare(strict_types=1);

namespace Neo\Security\Crypt\Commands;

use Neo\Foundation\Config\Env;
use Neo\Foundation\Console\Commands\Command;
use Neo\Foundation\Kernel\Kernel;
use Neo\Security\Crypt\CryptManager;

class GenerateAppKeyCommand extends Command
{
    protected string $signature = 'key:generate';

    protected string $description = 'Генерирует ключ приложения и записывает значение в файл .env';

    public bool $disableMounting = true;

    public function __construct(private readonly Kernel $kernel)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = $this->kernel->envPath() ?? $this->kernel->basePath('.env');

        try {
            Env::writeToFile($path, 'APP_KEY', CryptManager::generateKey());
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Ключ записан в ' . $path);

        return self::SUCCESS;
    }
}
