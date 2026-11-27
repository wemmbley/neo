<?php

declare(strict_types=1);

namespace Neo\Foundation\Kernel\Bootstrappers;

use Neo\Diagnostic\Testing\Commands\RunTestsCommand;
use Neo\Foundation\Config\Config;
use Neo\Foundation\Console\CommandProcessor;
use Neo\Foundation\Console\Commands\HelpCommand;
use Neo\Foundation\Console\Commands\CapacityCommand;
use Neo\Foundation\Console\Output;
use Neo\Foundation\Kernel\BootstrapperContract;
use Neo\Foundation\Kernel\Commands\ConfigShowCommand;
use Neo\Foundation\Kernel\Commands\EnvSetCommand;
use Neo\Foundation\Kernel\Commands\ProvidersCommand;
use Neo\Foundation\Kernel\Commands\ValidateCommand;
use Neo\Foundation\Kernel\Kernel;
use Neo\Foundation\Kernel\ServiceContainer;

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
        ValidateCommand::class,
        RunTestsCommand::class,
        CapacityCommand::class,
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
