<?php

declare(strict_types=1);

namespace App\Commands;

use Neo\Console\Commands\Command;

class HelloCommand extends Command
{
    protected string $signature = 'hello {name=мир : Кого приветствовать} {--S|shout : Кричать}';

    protected string $description = 'Здоровается';

    public function handle(): int
    {
        $text = 'Привет, ' . $this->argument('name') . '!';

        $this->info($this->option('shout') ? mb_strtoupper($text) : $text);

        return self::SUCCESS;
    }
}
