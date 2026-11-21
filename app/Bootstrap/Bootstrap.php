<?php

declare(strict_types=1);

namespace app\Bootstrap;

use Neo\Autoload\AliasLoader;
use Neo\Autoload\Autoload;
use Neo\Config\Config;
use Neo\Console\CommandProcessor;
use Neo\Kernel\Kernel;
use Neo\Kernel\ServiceContainer;

/**
 * Точка входа для web (run) и консоли (console).
 *
 * Принимает массив из app/Config/Bootstrap.php:
 *   base_path, autoload.classes, autoload.files.
 */
class Bootstrap
{
    private ?Kernel $kernel = null;

    /**
     * @param array{base_path: string, autoload: array{classes: array<string,string>, files?: list<string>}} $config
     */
    public function __construct(private readonly array $config) {}

    public function run(): void
    {
        $this->setDefaultTimezone();
        $this->kernel()->run();
    }

    public function console(): int
    {
        $this->setDefaultTimezone();

        try {
            return $this->kernel()->make(CommandProcessor::class)->run();
        } catch (\Throwable $e) {
            fwrite(STDERR, $e::class . ': ' . $e->getMessage() . PHP_EOL);

            return 1;
        }
    }

    public function kernel(): Kernel
    {
        if ($this->kernel !== null) {
            return $this->kernel;
        }

        $this->registerAutoload();

        $kernel = new Kernel(new ServiceContainer(), $this->config['base_path']);
        $kernel->bootstrap();

        /** @var Config $config */
        $config = $kernel->make(Config::class);

        (new AliasLoader())
            ->facades((array) $config->get('app.facades', []))
            ->register();

        return $this->kernel = $kernel;
    }

    private function registerAutoload(): void
    {

        #
        # Сам загрузчик подключаем руками: автозагрузки ещё нет.
        #
        require_once $this->config['base_path'] . '/src/Autoload/Autoload.php';

        (new Autoload(
            $this->config['autoload']['classes'],
            $this->config['autoload']['files'] ?? [],
        ))->load();

    }

    private function setDefaultTimezone(): void
    {

        #
        # Мы не доверяем хостингам и их php.ini
        # Для наших таймзон мы должны точку отсчёта установить строго в UTC.
        #
        date_default_timezone_set('UTC');

    }
}
