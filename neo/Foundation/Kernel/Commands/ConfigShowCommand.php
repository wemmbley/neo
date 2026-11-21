<?php

declare(strict_types=1);

namespace Neo\Foundation\Kernel\Commands;

use Neo\Foundation\Config\Config;
use Neo\Foundation\Console\Commands\Command;

class ConfigShowCommand extends Command
{
    protected string $signature = 'config:show {key? : Ключ через точку, например app.name}';

    protected string $description = 'Показывает настройки (все или по ключу)';

    public bool $disableMounting = true;

    public function __construct(private readonly Config $config)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $key = $this->argument('key');

        if ($key !== null && !$this->config->has($key)) {
            $this->error("Настройка \"{$key}\" не найдена.");

            return self::FAILURE;
        }

        $value = $key === null ? $this->config->all() : $this->config->get($key);

        $this->line(
            is_scalar($value) || $value === null
                ? var_export($value, true)
                : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)
        );

        return self::SUCCESS;
    }
}
