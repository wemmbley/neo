<?php

declare(strict_types=1);

namespace App\Bootstrap;

use Neo\Foundation\Autoload\AliasLoader;
use Neo\Foundation\Autoload\Autoload;
use Neo\Foundation\Config\Config;
use Neo\Foundation\Console\CommandProcessor;
use Neo\Foundation\Kernel\Kernel;
use Neo\Foundation\Kernel\Requirements;
use Neo\Foundation\Kernel\ServiceContainer;

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

        $this->checkRequirements();
        $this->registerAutoload();

        $kernel = new Kernel(
            new ServiceContainer(),
            $this->config['base_path'],
            envFile: $this->config['env'] ?? null,
        );
        $kernel->bootstrap();

        /** @var Config $config */
        $config = $kernel->make(Config::class);

        new AliasLoader()
            ->facades((array) $config->get('app.facades', []))
            ->register();

        return $this->kernel = $kernel;
    }

    private function registerAutoload(): void
    {

        #
        # Сам загрузчик подключаем руками: автозагрузки ещё нет.
        #
        require_once $this->config['autoload']['loader'];

        new Autoload(
            $this->config['autoload']['classes'],
            $this->config['autoload']['files'] ?? [],
        )->load();

    }

    private function checkRequirements(): void
    {
        if (!isset($this->config['requires'])) {
            return;
        }

        #
        # `php cli validate` сам проверяет requires и выводит все проблемы списком,
        # поэтому ему нельзя падать на первой же недостающей зависимости.
        #
        if (PHP_SAPI === 'cli' && ($_SERVER['argv'][1] ?? null) === 'validate') {
            return;
        }

        require_once $this->config['autoload']['requirements'];

        Requirements::check($this->config['requires']);
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
