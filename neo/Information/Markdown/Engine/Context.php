<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Engine;

use Neo\Information\Markdown\Contracts\TemplateRendererContract;
use Neo\Information\Markdown\Support\UrlGuard;
use Neo\Information\Markdown\Syntax\SyntaxRegistry;
use Neo\Information\Markdown\Transfer\HtmlModeTransfer;

/**
 * Состояние разбора одного документа. Передаётся во все синтаксисы.
 */
final class Context
{
    private const MAX_DEPTH = 24;

    private int $depth = 0;
    private int $links = 0;

    private readonly Slugger $slugger;
    private readonly BlockParser $blockParser;
    private readonly InlineParser $inlineParser;

    public function __construct(
        public readonly HtmlModeTransfer $mode,
        private readonly SyntaxRegistry $registry,
        private readonly TemplateRendererContract $renderer,
    ) {
        $this->slugger = new Slugger();
        $this->blockParser = new BlockParser();
        $this->inlineParser = new InlineParser();
    }

    public function registry(): SyntaxRegistry
    {
        return $this->registry;
    }

    /**
     * Обычный текст между синтаксисами → безопасный HTML по режимам `escape`/`sanitize`.
     */
    public function text(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        if ($this->mode->escape) {
            return self::escape($raw);
        }

        return $this->mode->sanitize ? self::escape(strip_tags($raw)) : $raw;
    }

    /**
     * HTML → простой текст (для поля `text` и якорей).
     */
    public function plain(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Можно ли использовать URL в src/href при текущем режиме `sanitize`.
     */
    public function safeUrl(string $url): bool
    {
        return !$this->mode->sanitize || UrlGuard::isSafe($url);
    }

    public function slug(string $text): string
    {
        return $this->slugger->make($text);
    }

    /**
     * Идёт ли сейчас разбор внутри текста ссылки (вложенные <a> недопустимы).
     */
    public function insideLink(): bool
    {
        return $this->links > 0;
    }

    /**
     * Выполняет $callback как содержимое ссылки.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function inLink(callable $callback): mixed
    {
        $this->links++;

        try {
            return $callback();
        } finally {
            $this->links--;
        }
    }

    /**
     * Inline-markdown → HTML.
     */
    public function inline(string $markdown): string
    {
        if ($this->depth >= self::MAX_DEPTH) {
            return $this->text($markdown);
        }

        $this->depth++;

        try {
            return $this->inlineParser->parse($markdown, $this);
        } finally {
            $this->depth--;
        }
    }

    /**
     * Блочный markdown → элементы.
     *
     * @param list<string> $lines
     * @param int          $offset номер строки перед первой строкой (для `line`)
     *
     * @return list<array<string, mixed>>
     */
    public function blocks(array $lines, int $offset = 0): array
    {
        if ($this->depth >= self::MAX_DEPTH) {
            $raw = implode("\n", $lines);

            return [$this->element('paragraph', ['content' => $this->text($raw), 'text' => $raw], $offset + 1)];
        }

        $this->depth++;

        try {
            return $this->blockParser->parse($lines, $this, $offset);
        } finally {
            $this->depth--;
        }
    }

    /**
     * Собирает элемент: атрибуты → Transfer → синтаксис → blade.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array{type: string, line: int, data: array<string, mixed>, html: string}
     */
    public function element(string $name, array $attributes, int $line = 0): array
    {
        [$syntax, $html] = $this->make($name, $attributes);

        return ['type' => $name, 'line' => $line, 'data' => $syntax->data(), 'html' => $html];
    }

    /**
     * То же, но только HTML.
     *
     * @param array<string, mixed> $attributes
     */
    public function html(string $name, array $attributes): string
    {
        return $this->make($name, $attributes)[1];
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array{0: \Neo\Information\Markdown\Contracts\SyntaxContract, 1: string}
     */
    private function make(string $name, array $attributes): array
    {
        $definition = $this->registry->get($name);

        $transfer = new ($definition->transfer)($attributes);
        $syntax = new ($definition->syntax)($transfer);

        return [$syntax, $this->renderer->render($definition->template, ['el' => $transfer, 'syntax' => $syntax])];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }
}
