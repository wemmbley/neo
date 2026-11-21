<?php

declare(strict_types=1);

namespace Neo\Kernel\Bootstrappers;

use Neo\Config\Config;
use Neo\Console\CommandProcessor;
use Neo\Console\Commands\HelpCommand;
use Neo\Console\Output;
use Neo\Kernel\BootstrapperContract;
use Neo\Kernel\Commands\ConfigShowCommand;
use Neo\Kernel\Commands\EnvSetCommand;
use Neo\Kernel\Commands\ProvidersCommand;
use Neo\Kernel\Kernel;
use Neo\Kernel\ServiceContainer;

/**
 * Шаг 3. Привязывает консоль. Всё лениво: в вебе Output и
 * CommandProcessor не создаются (STDOUT там не существует).
 *
 * Команды = встроенные + `console.commands` из конфига
 * (+ те, что добавят провайдеры через commands()).
 */
final class ConsoleBootstrapper implements BootstrapperContract
{
    /** @var list<class-string> */
    private const array BUILT_IN = [
        HelpCommand::class,
        ConfigShowCommand::class,
        EnvSetCommand::class,
        ProvidersCommand::class,
    ];

    public function bootstrap(Kernel $kernel): void
    {
        $container = $kernel->getContainer();

        if (!$container->has(Output::class)) {
            $container->singleton(Output::class, static fn (): Output => new Output());
        }

        $container->singleton(
            CommandProcessor::class,
            static function (ServiceContainer $container) use ($kernel): CommandProcessor {
                /** @var Config $config */
                $config = $container->make(Config::class);

                $commands = array_values(array_unique([
                    ...self::BUILT_IN,
                    ...(array) $config->get('console.commands', []),
                ]));

                return new CommandProcessor(
                    container: $container,
                    arguments: $_SERVER['argv'] ?? [],
                    commands: $commands,
                    output: $container->make(Output::class),
                )->mount(static fn () => $kernel->boot());
            },
        );
    }
}
