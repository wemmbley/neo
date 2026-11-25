<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Storage\Commands;

use Neo\Foundation\Console\Commands\Command;
use function symlink;

class StorageLinkCommand extends Command
{
    protected string $signature = 'storage:link';
    protected string $description = 'Создаёт symlink стореджа в публичную директорию.';

    public function handle(): int
    {
        if (config('app::os') === 'win') {
            $this->error('На Windows линковка недоступна.');

            return 0;
        }

        $links = config('storage.links', []);

        if (!is_array($links) || $links === []) {
            $this->warn('Список ссылок (storage::links) пуст.');

            return 0;
        }

        $hasError = false;

        foreach ($links as $from => $to) {
            if (!file_exists($from)) {
                $this->error("Исходный путь не существует: [{$from}]");
                $hasError = true;
                continue;
            }

            if (is_link($to)) {
                $currentTarget = readlink($to);

                if ($currentTarget === $from) {
                    $this->info("Симлинк уже существует и актуален: [{$to}] -> [{$from}]");
                    continue;
                }

                // Если симлинк ведёт не туда — пересоздаём
                if (!@unlink($to)) {
                    $this->error("Не удалось удалить старый симлинк: [{$to}]");
                    $hasError = true;
                    continue;
                }
            } elseif (file_exists($to)) {
                $this->error("Целевой путь уже существует и не является симлинком: [{$to}]");
                $hasError = true;
                continue;
            }

            $parentDir = dirname($to);
            if (!is_dir($parentDir) && !@mkdir($parentDir, 0755, true) && !is_dir($parentDir)) {
                $this->error("Не удалось создать директорию для симлинка: [{$parentDir}]");
                $hasError = true;
                continue;
            }

            if (@symlink($from, $to)) {
                $this->info("Создан симлинк: [{$to}] -> [{$from}]");
            } else {
                $this->error("Не удалось создать симлинк: [{$to}] -> [{$from}]");
                $hasError = true;
            }
        }

        return $hasError ? 1 : 0;
    }
}