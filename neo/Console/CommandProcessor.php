<?php

declare(strict_types=1);

namespace Neo\Console;

use Closure;
use InvalidArgumentException;
use Neo\Console\Commands\Command;
use Neo\Console\Commands\HelpCommand;
use Neo\Kernel\ServiceContainer;

/**
 * Консольное приложение: регистрирует классы команд и запускает нужную.
 *
 * Пример:
 *
 * exit(
 *     (new CommandProcessor(
 *         container: new Container(),
 *         arguments: $argv,
 *         commands: [
 *             FooCommand::class,
 *             TestCommand::class,
 *         ],
 *     ))
 *     ->mount(fn () => (new Bootstrap())->boot())
 *     ->run()
 * );
 *
 * Измышления:
 * Мы думали сделать модуль независимым, однако это теряет всякий
 * смысл и удобство. Зависимость от контейнера позволяет внутри
 * конструктора любой команды реализовать паттерн DependencyInjection
 * автоматически создав все обьекты, а так же позволяет при
 * конструкторе самого процессора писать классы команд, вместо
 * указания прямой директории нахождения конкретной команды.
 */
class CommandProcessor
{
    protected ?Closure $mounter = null;

    protected Output $output;

    public function __construct(
        protected ServiceContainer $container,
        protected array $arguments = [],
        protected array $commands = [],
        ?Output $output = null,
    ) {
        $this->output = $output ?? new Output();

        $classes = $this->commands;
        $this->commands = [];

        foreach ($classes as $class) {
            $this->load($class);
        }
    }

    public function mount(callable $callback): static
    {
        $this->mounter = Closure::fromCallable($callback);

        return $this;
    }

    /**
     * @return array<string, Command>
     */
    public function commands(): array
    {
        return $this->commands;
    }

    public function run(): int
    {
        $tokens = array_slice($this->arguments, 1);
        $name = array_shift($tokens);

        if ($name === null) {
            return $this->notFound('');
        }

        if (!isset($this->commands[$name])) {
            return $this->notFound($name);
        }

        return $this->commands[$name]->run(
            $tokens,
            $this->output,
            $this->mounter,
        );
    }

    protected function add(Command $command): void
    {
        $name = $command->getName();

        if (isset($this->commands[$name])) {
            throw new InvalidArgumentException(
                "Команда с именем '{$name}' уже зарегистрирована."
            );
        }

        if ($command instanceof HelpCommand) {
            $command->setProcessor($this);
        }

        $this->commands[$name] = $command;
    }

    /**
     * @param class-string<Command> $class
     */
    protected function load(string $class): void
    {
        if (!class_exists($class)) {
            throw new InvalidArgumentException(
                "Класс команды не найден: {$class}"
            );
        }

        if (!is_subclass_of($class, Command::class)) {
            throw new InvalidArgumentException(
                "Класс {$class} должен наследоваться от "
                . Command::class . '.'
            );
        }

        $command = $this->container->make($class);

        if (!$command instanceof Command) {
            throw new InvalidArgumentException(
                "Контейнер не смог создать команду: {$class}"
            );
        }

        $this->add($command);
    }

    protected function notFound(string $name): int
    {
        $this->output->error(
            $name === ''
                ? 'Не указана команда.'
                : "Команда \"{$name}\" не найдена."
        );

        $similar = array_filter(
            array_keys($this->commands),
            static fn (string $command): bool =>
                levenshtein($name, $command) <= 3
                || str_contains($command, $name)
        );

        if ($similar) {
            $this->output->line(
                'Возможно, вы имели в виду: '
                . implode(', ', $similar)
            );
        }

        return Command::FAILURE;
    }
}