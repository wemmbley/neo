<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax;

use LogicException;
use Neo\Information\Markdown\Contracts\BlockSyntaxContract;
use Neo\Information\Markdown\Contracts\InlineSyntaxContract;
use Neo\Information\Markdown\Contracts\SyntaxContract;
use Neo\Information\Markdown\Transfer\MarkdownTransfer;
use Neo\Transfer\Transfer;
use ReflectionClass;

/**
 * Реестр привязок «синтаксис → Transfer → шаблон».
 *
 * Регистрация по имени: повторный register() с тем же name() заменяет привязку.
 * Так переопределяется шаблон или Transfer любого встроенного синтаксиса.
 */
final class SyntaxRegistry
{
    /** @var array<string, SyntaxDefinition> */
    private array $definitions = [];

    /** @var array<string, array{0: string, 1: string}> файлы класса синтаксиса и Transfer */
    private array $files = [];

    private int $version = 0;

    /** @var list<SyntaxDefinition>|null */
    private ?array $blocks = null;

    /** @var list<SyntaxDefinition>|null */
    private ?array $inline = null;

    /** @var array<string, list<SyntaxDefinition>>|null */
    private ?array $triggers = null;

    /**
     * Реестр со всеми встроенными синтаксисами.
     */
    public static function withCore(): self
    {
        $registry = new self();

        foreach (CoreSyntax::all() as $class) {
            $registry->register($class);
        }

        return $registry;
    }

    /**
     * Встроенные синтаксисы + привязки из config['syntax'].
     */
    public static function fromConfig(MarkdownTransfer $config): self
    {
        $registry = self::withCore();

        foreach ($config->syntax as $key => $value) {
            if (is_int($key)) {
                $registry->register($value);
                continue;
            }

            $registry->register($key, $value['transfer'] ?? null, $value['template'] ?? null);
        }

        return $registry;
    }

    /**
     * @param class-string<SyntaxContract> $syntax
     * @param class-string<Transfer>|null  $transfer null — Transfer по умолчанию синтаксиса
     * @param string|null                  $template null — шаблон по умолчанию синтаксиса
     */
    public function register(string $syntax, ?string $transfer = null, ?string $template = null): self
    {
        if (!is_subclass_of($syntax, SyntaxContract::class)) {
            throw new LogicException(sprintf('%s must implement %s.', $syntax, SyntaxContract::class));
        }

        if ((new ReflectionClass($syntax))->isAbstract()) {
            throw new LogicException(sprintf('%s is abstract and cannot be registered.', $syntax));
        }

        $default = $syntax::transfer();
        $transfer ??= $default;

        // Синтаксис принимает в конструктор именно свой Transfer: свой нужно наследовать от него.
        if (!is_a($transfer, $default, true)) {
            throw new LogicException(sprintf(
                'Transfer %s of %s must be %s or extend it.',
                $transfer,
                $syntax,
                $default,
            ));
        }

        $template ??= $syntax::template();

        if (!is_file($template)) {
            throw new LogicException(sprintf('Template for %s not found: %s', $syntax, $template));
        }

        $definition = new SyntaxDefinition(
            $syntax::name(),
            $syntax,
            $transfer,
            $template,
            $syntax::priority(),
            is_subclass_of($syntax, BlockSyntaxContract::class),
            is_subclass_of($syntax, InlineSyntaxContract::class),
        );

        $this->definitions[$definition->name] = $definition;
        $this->files[$definition->name] = [
            (string) (new ReflectionClass($syntax))->getFileName(),
            (string) (new ReflectionClass($transfer))->getFileName(),
        ];
        $this->touch();

        return $this;
    }

    /**
     * Удаляет синтаксис (класс или имя): он перестаёт распознаваться.
     */
    public function forget(string $syntaxOrName): self
    {
        $name = is_subclass_of($syntaxOrName, SyntaxContract::class) ? $syntaxOrName::name() : $syntaxOrName;

        unset($this->definitions[$name], $this->files[$name]);
        $this->touch();

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    public function get(string $name): SyntaxDefinition
    {
        return $this->definitions[$name]
            ?? throw new LogicException(sprintf('Syntax "%s" is not registered.', $name));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->definitions);
    }

    /**
     * Блочные синтаксисы по приоритету.
     *
     * @return list<SyntaxDefinition>
     */
    public function blocks(): array
    {
        return $this->blocks ??= $this->sorted(fn (SyntaxDefinition $d): bool => $d->block);
    }

    /**
     * @return list<SyntaxDefinition>
     */
    public function inline(): array
    {
        return $this->inline ??= $this->sorted(fn (SyntaxDefinition $d): bool => $d->inline);
    }

    /**
     * Inline-синтаксисы по первому символу.
     *
     * @return array<string, list<SyntaxDefinition>>
     */
    public function triggers(): array
    {
        if ($this->triggers !== null) {
            return $this->triggers;
        }

        $map = [];

        foreach ($this->inline() as $definition) {
            foreach (array_unique(str_split($definition->syntax::triggers())) as $char) {
                $map[$char][] = $definition;
            }
        }

        return $this->triggers = $map;
    }

    /**
     * Меняется при каждой регистрации/удалении.
     */
    public function version(): int
    {
        return $this->version;
    }

    /**
     * Отпечаток набора привязок, их шаблонов и классов — часть ключа кэша.
     * Время изменения файлов читается при каждом вызове: правка blade сбрасывает кэш.
     */
    public function fingerprint(): string
    {
        $parts = [];

        foreach ($this->definitions as $d) {
            $parts[] = implode(':', [
                $d->name,
                $d->syntax,
                $d->transfer,
                $d->template,
                (int) @filemtime($d->template),
                (int) @filemtime($this->files[$d->name][0]),
                (int) @filemtime($this->files[$d->name][1]),
            ]);
        }

        return sha1(implode('|', $parts));
    }

    /**
     * @param callable(SyntaxDefinition): bool $filter
     *
     * @return list<SyntaxDefinition>
     */
    private function sorted(callable $filter): array
    {
        $list = array_values(array_filter($this->definitions, $filter));

        usort($list, fn (SyntaxDefinition $a, SyntaxDefinition $b): int => $a->priority <=> $b->priority);

        return $list;
    }

    private function touch(): void
    {
        $this->version++;
        $this->blocks = $this->inline = $this->triggers = null;
    }
}
