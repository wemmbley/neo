<?php

declare(strict_types=1);

namespace Neo\Console\Commands;

use LogicException;
use Neo\Console\CommandProcessor;

/**
 * Встроенная справка: список команд или описание одной команды.
 */
class HelpCommand extends Command
{
    protected string $signature = 'help {command? : Имя команды}';

    protected string $description = 'Список команд или справка по одной команде';

    public bool $disableMounting = true;

    protected ?CommandProcessor $processor = null;

    public function setProcessor(CommandProcessor $processor): static
    {
        $this->processor = $processor;

        return $this;
    }

    public function handle(): int
    {
        if ($this->processor === null) {
            throw new LogicException(
                'HelpCommand не привязана к CommandProcessor.'
            );
        }

        $commands = $this->processor->commands();
        $name = $this->argument('command');

        if ($name === null) {
            ksort($commands);
            $this->section('Использование:', [['команда [аргументы] [опции]', '']]);
            $this->section('Команды:', array_map(
                static fn (Command $c) => [$c->getName(), $c->getDescription()],
                $commands
            ));

            return self::SUCCESS;
        }

        if (!isset($commands[$name])) {
            $this->error("Команда \"{$name}\" не найдена.");

            return self::FAILURE;
        }

        $input = $commands[$name]->getInput();

        $this->section('Описание:', [[$commands[$name]->getDescription(), '']]);
        $this->section('Использование:', [[$input->usage(), '']]);

        $this->section('Аргументы:', array_map(
            static fn (string $n, array $a) => [$n, $a['description']],
            array_keys($input->arguments),
            $input->arguments
        ));

        $this->section('Опции:', array_map(
            static fn (string $n, array $o) => [($o['shortcut'] ? "-{$o['shortcut']}, " : '') . "--{$n}", $o['description']],
            array_keys($input->options),
            $input->options
        ));

        return self::SUCCESS;
    }

    /**
     * Печатает раздел справки: заголовок и выровненные строки «метка — текст».
     * Пустой раздел не выводится.
     *
     * @param array<int, array{0: string, 1: string}> $rows
     */
    protected function section(string $title, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $width = max(array_map(static fn (array $r) => strlen($r[0]), $rows));

        $this->comment($title);

        foreach ($rows as [$label, $text]) {
            $this->line('  ' . $this->output->paint($label, 'info')
                . ($text === '' ? '' : str_repeat(' ', $width - strlen($label) + 2) . $text));
        }

        $this->newLine();
    }
}