<?php

declare(strict_types=1);

use Neo\Logger\Drivers\Basic\BasicReportDriver;
use Neo\Logger\Managers\LogManager;
use Neo\Logger\Transfers\LoggerTransfer;

/**
 * Создаёт уникальный временный каталог и удаляет его при завершении процесса.
 */
function logger_tmp_dir(): string
{
    $dir = sys_get_temp_dir() . '/neo-logger-' . bin2hex(random_bytes(6));
    mkdir($dir, 0755, true);

    register_shutdown_function(static function () use ($dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    });

    return $dir;
}

/** Содержимое файла или пустая строка, если файла нет. */
function logger_read(string $path): string
{
    return is_file($path) ? (string) file_get_contents($path) : '';
}

/**
 * Менеджер с рабочей конфигурацией: single, daily, monthly, stack, off и канал file.
 *
 * @param array<string, mixed> $overrides Ключи default / drivers / channels заменяют стандартные.
 */
function logger_manager(string $dir, array $overrides = []): LogManager
{
    return new LogManager(new LoggerTransfer($overrides + [
            'default' => 'single',
            'drivers' => [
                'single'  => ['driver' => 'single',  'path' => $dir . '/app.log'],
                'daily'   => ['driver' => 'daily',   'path' => $dir . '/daily.log'],
                'monthly' => ['driver' => 'monthly', 'path' => $dir . '/monthly.log'],
                'both'    => ['driver' => 'stack',   'drivers' => ['single', 'daily']],
                'off'     => ['driver' => 'disabled'],
            ],
            'channels' => [
                'file' => ['channel' => 'file', 'driver' => 'single'],
            ],
        ]));
}

/**
 * Драйвер-шпион: запоминает записи в $records или бросает исключение, если $fails = true.
 */
function logger_spy(bool $fails = false): BasicReportDriver
{
    return new class([], $fails) extends BasicReportDriver {
        /** @var array<int, array{level: string, message: string, context: array}> */
        public array $records = [];

        public function __construct(array $config, private bool $fails)
        {
            parent::__construct($config);
        }

        protected function write(string $level, string $message, array $context = []): void
        {
            if ($this->fails) {
                throw new RuntimeException('spy failed');
            }

            $this->records[] = compact('level', 'message', 'context');
        }
    };
}