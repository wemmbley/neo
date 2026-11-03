<?php

declare(strict_types=1);

use Neo\Console\CommandProcessor;
use Neo\Console\Commands\Command;
use Neo\Console\Commands\HelpCommand;
use Neo\Console\Output;
use Neo\Kernel\ServiceContainer;

beforeEach(function () {
    $this->stream = fopen('php://memory', 'w+');

    $this->output = new Output($this->stream, false);

    $this->container = new ServiceContainer();
});

afterEach(function () {
    if (is_resource($this->stream)) {
        fclose($this->stream);
    }
});

function consoleOutput($stream): string
{
    rewind($stream);

    return stream_get_contents($stream);
}

/*
|--------------------------------------------------------------------------
| Test commands
|--------------------------------------------------------------------------
*/

class TestHelloCommand extends Command
{
    protected string $signature = 'hello {name=мир} {--shout}';

    protected string $description = 'Здоровается';

    public static int $calls = 0;

    public static array $receivedArguments = [];

    public static array $receivedOptions = [];

    public function handle(): int
    {
        self::$calls++;

        self::$receivedArguments = $this->arguments();
        self::$receivedOptions = $this->options();

        $message = 'Привет, ' . $this->argument('name');

        $this->info(
            $this->option('shout')
                ? mb_strtoupper($message)
                : $message
        );

        return self::SUCCESS;
    }
}

class TestFailureCommand extends Command
{
    protected string $signature = 'failure';

    public function handle(): int
    {
        return self::FAILURE;
    }
}

class TestNullCommand extends Command
{
    protected string $signature = 'null';

    public function handle(): ?int
    {
        return null;
    }
}

class TestInvalidCommand
{
}

class TestEmptySignatureCommand extends Command
{
    public function handle(): int
    {
        return self::SUCCESS;
    }
}

class TestDuplicateCommand extends Command
{
    protected string $signature = 'hello';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}

class TestMountCommand extends Command
{
    protected string $signature = 'mount:test';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}

class TestDisabledMountCommand extends Command
{
    protected string $signature = 'mount:disabled';

    public bool $disableMounting = true;

    public function handle(): int
    {
        return self::SUCCESS;
    }
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function makeProcessor(
    ServiceContainer $container,
    array $arguments = [],
    array $commands = [],
    ?Output $output = null,
): CommandProcessor {
    return new CommandProcessor(
        container: $container,
        arguments: $arguments,
        commands: $commands,
        output: $output,
    );
}

/*
|--------------------------------------------------------------------------
| Construction and registration
|--------------------------------------------------------------------------
*/

describe('Construction', function () {

    it('creates processor', function () {
        $processor = makeProcessor($this->container);

        expect($processor)
            ->toBeInstanceOf(CommandProcessor::class);
    });

    it('registers commands from class names', function () {
        $processor = makeProcessor(
            $this->container,
            [],
            [TestHelloCommand::class],
        );

        expect($processor->commands())
            ->toHaveKey('hello')
            ->and($processor->commands()['hello'])
            ->toBeInstanceOf(TestHelloCommand::class);
    });

    it('returns an empty command list when no commands are registered', function () {
        expect(makeProcessor($this->container)->commands())
            ->toBe([]);
    });

    it('resolves commands through service container', function () {
        $command = new TestHelloCommand();

        $this->container->instance(
            TestHelloCommand::class,
            $command
        );

        $processor = makeProcessor(
            $this->container,
            [],
            [TestHelloCommand::class],
        );

        expect($processor->commands()['hello'])
            ->toBe($command);
    });

    it('throws when command class does not exist', function () {
        expect(fn () => makeProcessor(
            $this->container,
            [],
            ['Neo\\Console\\Commands\\MissingCommand'],
        ))->toThrow(InvalidArgumentException::class);
    });

    it('throws when class does not extend Command', function () {
        expect(fn () => makeProcessor(
            $this->container,
            [],
            [TestInvalidCommand::class],
        ))->toThrow(InvalidArgumentException::class);
    });

    it('throws when container does not resolve Command', function () {
        $this->container->instance(
            TestHelloCommand::class,
            new stdClass()
        );

        expect(fn () => makeProcessor(
            $this->container,
            [],
            [TestHelloCommand::class],
        ))->toThrow(InvalidArgumentException::class);
    });

    it('throws when duplicate command names are registered', function () {
        expect(fn () => makeProcessor(
            $this->container,
            [],
            [
                TestHelloCommand::class,
                TestDuplicateCommand::class,
            ],
        ))->toThrow(InvalidArgumentException::class);
    });

    it('throws when command signature is empty', function () {
        expect(fn () => makeProcessor(
            $this->container,
            [],
            [TestEmptySignatureCommand::class],
        ))->toThrow(LogicException::class);
    });

});

/*
|--------------------------------------------------------------------------
| Command execution
|--------------------------------------------------------------------------
*/

describe('Execution', function () {

    it('runs registered command', function () {
        $processor = makeProcessor(
            $this->container,
            ['console', 'hello'],
            [TestHelloCommand::class],
            $this->output,
        );

        expect($processor->run())
            ->toBe(Command::SUCCESS);

        expect(consoleOutput($this->stream))
            ->toContain('Привет, мир');
    });

    it('passes command arguments', function () {
        $processor = makeProcessor(
            $this->container,
            ['console', 'hello', 'Анна'],
            [TestHelloCommand::class],
            $this->output,
        );

        $processor->run();

        expect(TestHelloCommand::$receivedArguments)
            ->toHaveKey('name', 'Анна');
    });

    it('passes command options', function () {
        $processor = makeProcessor(
            $this->container,
            ['console', 'hello', 'Анна', '--shout'],
            [TestHelloCommand::class],
            $this->output,
        );

        $processor->run();

        expect(TestHelloCommand::$receivedOptions)
            ->toHaveKey('shout');

        expect(consoleOutput($this->stream))
            ->toContain('ПРИВЕТ, АННА');
    });

    it('returns command failure code', function () {
        $processor = makeProcessor(
            $this->container,
            ['console', 'failure'],
            [TestFailureCommand::class],
            $this->output,
        );

        expect($processor->run())
            ->toBe(Command::FAILURE);
    });

    it('converts null return code to success', function () {
        $processor = makeProcessor(
            $this->container,
            ['console', 'null'],
            [TestNullCommand::class],
            $this->output,
        );

        expect($processor->run())
            ->toBe(Command::SUCCESS);
    });

    it('does not execute unregistered commands', function () {
        TestHelloCommand::$calls = 0;

        $processor = makeProcessor(
            $this->container,
            ['console', 'hello'],
            [],
            $this->output,
        );

        expect($processor->run())
            ->toBe(Command::FAILURE);

        expect(TestHelloCommand::$calls)
            ->toBe(0);
    });

});

/*
|--------------------------------------------------------------------------
| Mount lifecycle
|--------------------------------------------------------------------------
*/

describe('Mount lifecycle', function () {

    it('executes mount callback before command', function () {
        $mounted = false;

        $processor = makeProcessor(
            $this->container,
            ['console', 'mount:test'],
            [TestMountCommand::class],
            $this->output,
        );

        $processor->mount(function () use (&$mounted) {
            $mounted = true;
        });

        $processor->run();

        expect($mounted)->toBeTrue();
    });

    it('does not execute mount when callback is not registered', function () {
        $processor = makeProcessor(
            $this->container,
            ['console', 'mount:test'],
            [TestMountCommand::class],
            $this->output,
        );

        expect($processor->run())
            ->toBe(Command::SUCCESS);
    });

    it('returns itself from mount', function () {
        $processor = makeProcessor($this->container);

        expect($processor->mount(fn () => null))
            ->toBe($processor);
    });

    it('does not mount commands with disableMounting', function () {
        $mounted = false;

        $processor = makeProcessor(
            $this->container,
            ['console', 'mount:disabled'],
            [TestDisabledMountCommand::class],
            $this->output,
        );

        $processor->mount(function () use (&$mounted) {
            $mounted = true;
        });

        expect($processor->run())
            ->toBe(Command::SUCCESS);

        expect($mounted)->toBeFalse();
    });

});

/*
|--------------------------------------------------------------------------
| Unknown commands
|--------------------------------------------------------------------------
*/

describe('Unknown commands', function () {

    it('returns failure when command name is missing', function () {
        $processor = makeProcessor(
            $this->container,
            ['console'],
            [],
            $this->output,
        );

        expect($processor->run())
            ->toBe(Command::FAILURE);

        expect(consoleOutput($this->stream))
            ->toContain('Не указана команда.');
    });

    it('returns failure when command does not exist', function () {
        $processor = makeProcessor(
            $this->container,
            ['console', 'unknown'],
            [],
            $this->output,
        );

        expect($processor->run())
            ->toBe(Command::FAILURE);

        expect(consoleOutput($this->stream))
            ->toContain('Команда "unknown" не найдена.');
    });

    it('suggests similar command names', function () {
        $processor = makeProcessor(
            $this->container,
            ['console', 'helo'],
            [TestHelloCommand::class],
            $this->output,
        );

        $processor->run();

        expect(consoleOutput($this->stream))
            ->toContain('Возможно, вы имели в виду:')
            ->toContain('hello');
    });

    it('does not suggest unrelated commands', function () {
        $processor = makeProcessor(
            $this->container,
            ['console', 'completely-unknown'],
            [TestHelloCommand::class],
            $this->output,
        );

        $processor->run();

        expect(consoleOutput($this->stream))
            ->not->toContain('Возможно, вы имели в виду:');
    });

    it('suggests commands containing the requested name', function () {
        $processor = makeProcessor(
            $this->container,
            ['console', 'hel'],
            [TestHelloCommand::class],
            $this->output,
        );

        $processor->run();

        expect(consoleOutput($this->stream))
            ->toContain('hello');
    });

});

/*
|--------------------------------------------------------------------------
| Help command integration
|--------------------------------------------------------------------------
*/

describe('Help integration', function () {

    it('registers processor inside HelpCommand', function () {
        $processor = makeProcessor(
            $this->container,
            [],
            [HelpCommand::class],
            $this->output,
        );

        expect($processor->commands())
            ->toHaveKey('help');
    });

    it('runs help command', function () {
        $processor = makeProcessor(
            $this->container,
            ['console', 'help'],
            [
                HelpCommand::class,
                TestHelloCommand::class,
            ],
            $this->output,
        );

        expect($processor->run())
            ->toBe(Command::SUCCESS);
    });

});
