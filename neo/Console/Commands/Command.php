<?php

declare(strict_types=1);

namespace Neo\Console\Commands;

use Closure;
use InvalidArgumentException;
use LogicException;
use Neo\Console\Input;
use Neo\Console\Output;

/**
 * Базовая консольная команда (API совместим с Laravel).
 *
 *   class HelloCommand extends Command
 *   {
 *       protected string $signature = 'hello {name=мир} {--shout}';
 *       protected string $description = 'Здоровается';
 *
 *       public function handle(): int
 *       {
 *           $this->info('Привет, ' . $this->argument('name'));
 *
 *           return self::SUCCESS;
 *       }
 *   }
 */
abstract class Command
{
    public const int SUCCESS = 0;
    public const int FAILURE = 1;
    public const int INVALID = 2;

    /** Сигнатура: имя команды, аргументы и опции. См. {@see Input}. */
    protected string $signature = '';

    /** Описание для справки. */
    protected string $description = '';

    /** true = не вызывать коллбек монтирования (например, для справки). */
    public bool $disableMounting = false;

    protected Input $input;

    protected Output $output;

    public function __construct()
    {
        $this->input = new Input($this->signature);

        if ($this->input->name === '') {
            throw new LogicException(static::class . ': не задана $signature.');
        }
    }

    /**
     * Тело команды.
     *
     * @return int|null Код выхода; null считается успехом.
     */
    abstract public function handle();

    /**
     * Запускает команду: разбор ввода → монтирование → handle().
     *
     * @param string[] $tokens Аргументы после имени команды.
     * @param Closure|null $mount Коллбек монтирования (бутстрап приложения).
     */
    public function run(array $tokens, Output $output, ?Closure $mount = null): int
    {
        $this->output = $output;

        try {
            $this->input->bind($tokens);
        } catch (InvalidArgumentException $e) {
            $output->error($e->getMessage());
            $output->line('Использование: ' . $this->input->usage());

            return self::INVALID;
        }

        if ($mount !== null && !$this->disableMounting) {
            $mount();
        }

        $code = $this->handle();

        return is_int($code) ? $code : self::SUCCESS;
    }

    public function getName(): string
    {
        return $this->input->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getInput(): Input
    {
        return $this->input;
    }

    public function argument(?string $key = null): mixed
    {
        return $this->input->argument($key);
    }

    /** @return array<string, mixed> */
    public function arguments(): array
    {
        return $this->input->argument();
    }

    public function option(?string $key = null): mixed
    {
        return $this->input->option($key);
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        return $this->input->option();
    }

    public function line(string $text = ''): void
    {
        $this->output->line($text);
    }

    public function newLine(int $count = 1): void
    {
        $this->output->newLine($count);
    }

    public function info(string $text): void
    {
        $this->output->info($text);
    }

    public function comment(string $text): void
    {
        $this->output->comment($text);
    }

    public function warn(string $text): void
    {
        $this->output->warn($text);
    }

    public function error(string $text): void
    {
        $this->output->error($text);
    }
}