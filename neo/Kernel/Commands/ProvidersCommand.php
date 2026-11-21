<?php

declare(strict_types=1);

namespace Neo\Kernel\Commands;

use Neo\Console\Commands\Command;
use Neo\Kernel\Kernel;
use Neo\Kernel\ServiceProvider;

class ProvidersCommand extends Command
{
    protected string $signature = 'kernel:providers';

    protected string $description = 'Список сервис-провайдеров и их состояние';

    public function __construct(private readonly Kernel $kernel)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $active = array_map(
            static fn (ServiceProvider $p): string => $p::class,
            $this->kernel->getActiveProviders(),
        );

        foreach ($this->kernel->getProviders() as $provider) {
            $class = is_string($provider) ? $provider : $provider::class;

            $this->line(
                str_pad($class, 50)
                . (in_array($class, $active, true) ? 'активен' : 'отложен (ленивый)')
            );
        }

        return self::SUCCESS;
    }
}
