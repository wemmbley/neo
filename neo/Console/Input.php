<?php

declare(strict_types=1);

namespace Neo\Console;

use InvalidArgumentException;

/**
 * Определение и значения аргументов и опций команды.
 *
 * Понимает сигнатуру в синтаксисе Laravel:
 *
 *   'test {path?} {--filter= : Фильтр по имени} {--Q|quiet}'
 *
 *   {name}          обязательный аргумент      {--flag}        флаг (false/true)
 *   {name?}         необязательный             {--opt=}        опция со значением
 *   {name=def}      со значением по умолчанию  {--opt=def}     со значением по умолчанию
 *   {name*}         массив значений            {--opt=*}       опция-массив
 *   {name : текст}  описание                   {--Q|quiet}     короткий синоним -Q
 */
class Input
{
    /** Имя команды (первое слово сигнатуры). */
    public readonly string $name;

    /** @var array<string, array{required: bool, array: bool, default: mixed, description: string}> */
    public array $arguments = [];

    /** @var array<string, array{shortcut: ?string, value: bool, array: bool, default: mixed, description: string}> */
    public array $options = [];

    /** @var array<string, mixed> */
    protected array $argumentValues = [];

    /** @var array<string, mixed> */
    protected array $optionValues = [];

    public function __construct(string $signature)
    {
        preg_match('/^\s*([^\s{]+)/', $signature, $match);
        $this->name = $match[1] ?? '';

        preg_match_all('/\{\s*(.+?)\s*\}/', $signature, $tokens);

        foreach ($tokens[1] as $token) {
            [$spec, $description] = array_pad(preg_split('/\s+:\s+/', $token, 2), 2, '');

            str_starts_with($spec, '--')
                ? $this->addOption(substr($spec, 2), $description)
                : $this->addArgument($spec, $description);
        }
    }

    /**
     * Разбирает токены командной строки (без имени команды).
     *
     * @param string[] $tokens
     * @throws InvalidArgumentException При неверном вводе.
     */
    public function bind(array $tokens): void
    {
        $positional = [];
        $this->optionValues = array_map(static fn (array $o) => $o['default'], $this->options);

        while (($token = array_shift($tokens)) !== null) {
            if ($token === '--') {
                array_push($positional, ...$tokens);
                break;
            }

            if (str_starts_with($token, '--')) {
                [$name, $inline] = array_pad(explode('=', substr($token, 2), 2), 2, null);
                $this->bindOption($name, $inline, $tokens);
            } elseif (strlen($token) > 1 && $token[0] === '-') {
                $name = $this->nameByShortcut($token[1])
                    ?? throw new InvalidArgumentException("Опция \"-{$token[1]}\" не существует.");
                $inline = strlen($token) > 2 ? ltrim(substr($token, 2), '=') : null;
                $this->bindOption($name, $inline, $tokens);
            } else {
                $positional[] = $token;
            }
        }

        $this->bindArguments($positional);
    }

    /** Значение аргумента или все аргументы, если ключ не указан. */
    public function argument(?string $key = null): mixed
    {
        return $this->fetch($this->argumentValues, $key, 'Аргумент');
    }

    /** Значение опции или все опции, если ключ не указан. */
    public function option(?string $key = null): mixed
    {
        return $this->fetch($this->optionValues, $key, 'Опция');
    }

    /** Строка использования для справки: `test [--filter=FILTER] [<path>]`. */
    public function usage(): string
    {
        $parts = [$this->name];

        foreach ($this->options as $name => $o) {
            $parts[] = '[' . ($o['shortcut'] ? "-{$o['shortcut']}|" : '') . "--{$name}"
                . ($o['value'] ? '=' . strtoupper($name) : '') . ']';
        }

        foreach ($this->arguments as $name => $a) {
            $item = "<{$name}>" . ($a['array'] ? '...' : '');
            $parts[] = $a['required'] ? $item : "[{$item}]";
        }

        return implode(' ', $parts);
    }

    protected function addArgument(string $spec, string $description): void
    {
        $array = $required = true;
        $default = null;

        if (str_ends_with($spec, '*')) {
            $spec = substr($spec, 0, -1);
        } else {
            $array = false;
        }

        if (str_ends_with($spec, '?')) {
            $spec = substr($spec, 0, -1);
            $required = false;
        }

        if (str_contains($spec, '=')) {
            [$spec, $default] = explode('=', $spec, 2);
            $required = false;
        }

        $this->arguments[$spec] = [
            'required'    => $required,
            'array'       => $array,
            'default'     => $array ? [] : $default,
            'description' => $description,
        ];
    }

    protected function addOption(string $spec, string $description): void
    {
        $shortcut = null;
        $default = false;
        $array = false;

        if (str_contains($spec, '|')) {
            [$shortcut, $spec] = explode('|', $spec, 2);
        }

        $value = str_contains($spec, '=');

        if ($value) {
            [$spec, $default] = explode('=', $spec, 2);

            if ($default === '*') {
                $array = true;
                $default = [];
            } elseif ($default === '') {
                $default = null;
            }
        }

        $this->options[$spec] = compact('shortcut', 'value', 'array', 'default', 'description');
    }

    /** @param string[] $tokens Остаток токенов (нужен, если значение идёт отдельным словом). */
    protected function bindOption(string $name, ?string $inline, array &$tokens): void
    {
        $option = $this->options[$name] ?? throw new InvalidArgumentException("Опция \"--{$name}\" не существует.");

        if (!$option['value']) {
            $inline === null || throw new InvalidArgumentException("Опция \"--{$name}\" не принимает значение.");
            $this->optionValues[$name] = true;

            return;
        }

        $value = $inline ?? array_shift($tokens)
            ?? throw new InvalidArgumentException("Опции \"--{$name}\" нужно значение.");

        if ($option['array']) {
            $this->optionValues[$name][] = $value;
        } else {
            $this->optionValues[$name] = $value;
        }
    }

    /** @param string[] $values */
    protected function bindArguments(array $values): void
    {
        $missing = [];

        foreach ($this->arguments as $name => $a) {
            $given = $a['array'] ? array_splice($values, 0) : array_splice($values, 0, 1);

            if ($given === []) {
                $a['required'] && $missing[] = $name;
                $this->argumentValues[$name] = $a['default'];
            } else {
                $this->argumentValues[$name] = $a['array'] ? $given : $given[0];
            }
        }

        if ($missing) {
            throw new InvalidArgumentException('Не хватает аргументов: ' . implode(', ', $missing) . '.');
        }

        if ($values) {
            throw new InvalidArgumentException('Лишние аргументы: ' . implode(' ', $values) . '.');
        }
    }

    protected function nameByShortcut(string $shortcut): ?string
    {
        foreach ($this->options as $name => $option) {
            if ($option['shortcut'] === $shortcut) {
                return $name;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $values */
    protected function fetch(array $values, ?string $key, string $what): mixed
    {
        if ($key === null) {
            return $values;
        }

        return array_key_exists($key, $values)
            ? $values[$key]
            : throw new InvalidArgumentException("{$what} \"{$key}\" не определён.");
    }
}