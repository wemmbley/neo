<?php

declare(strict_types=1);

namespace Neo\Information\Markdown;

use Neo\Information\Collection\Collection;
use Neo\Information\Markdown\Cache\FileCache;
use Neo\Information\Markdown\Contracts\TemplateRendererContract;
use Neo\Information\Markdown\Engine\BlockParser;
use Neo\Information\Markdown\Engine\Context;
use Neo\Information\Markdown\Exceptions\MarkdownException;
use Neo\Information\Markdown\Rendering\ViewRenderer;
use Neo\Information\Markdown\Syntax\SyntaxRegistry;
use Neo\Information\Markdown\Transfer\MarkdownTransfer;
use Neo\Presentation\View\View;

/**
 * Точка входа пакета.
 *
 *   new MarkdownManager(new MarkdownTransfer($config));
 *   Markdown::parse('path/file.md');
 */
final class MarkdownManager
{
    private const PACKAGE_VERSION = '2';

    private static ?self $instance = null;

    private readonly SyntaxRegistry $registry;
    private readonly TemplateRendererContract $renderer;
    private readonly FileCache $cache;

    public function __construct(
        private readonly MarkdownTransfer $config,
        ?SyntaxRegistry $registry = null,
        ?TemplateRendererContract $renderer = null,
    ) {
        $this->registry = $registry ?? SyntaxRegistry::fromConfig($config);
        $this->renderer = $renderer ?? new ViewRenderer();
        $this->cache = new FileCache($config->cache);

        self::$instance = $this;
    }

    /**
     * Последний созданный менеджер (его использует фасад Markdown).
     */
    public static function instance(): ?self
    {
        return self::$instance;
    }

    /**
     * Разбирает markdown-файл. Результат кэшируется по пути, mtime и размеру файла,
     * набору синтаксисов и их шаблонов.
     *
     * @return array<int, array<string, mixed>>|Collection|View зависит от `format`
     */
    public function parse(string $path)
    {
        $real = realpath($path);

        if ($real === false || !is_file($real)) {
            throw MarkdownException::fileNotFound($path);
        }

        $key = sha1(implode('|', [$real, (int) filemtime($real), (int) filesize($real), $this->fingerprint()]));

        $elements = $this->cache->remember($key, function () use ($real, $path): array {
            $source = @file_get_contents($real);

            if ($source === false) {
                throw MarkdownException::unreadable($path);
            }

            return $this->compile($source);
        });

        return $this->output($elements);
    }

    /**
     * Разбирает markdown-строку (без кэша).
     *
     * @return array<int, array<string, mixed>>|Collection|View зависит от `format`
     */
    public function parseString(string $markdown)
    {
        return $this->output($this->compile($markdown));
    }

    /**
     * Привязывает синтаксис к его Transfer и шаблону. Тот же name() заменяет существующий.
     *
     *   Markdown::registerSyntax(VideoSyntax::class, VideoSyntaxTransfer::class, '/views/video.blade.php');
     */
    public function registerSyntax(string $syntax, ?string $transfer = null, ?string $template = null): static
    {
        $this->registry->register($syntax, $transfer, $template);

        return $this;
    }

    public function unregisterSyntax(string $syntaxOrName): static
    {
        $this->registry->forget($syntaxOrName);

        return $this;
    }

    public function syntax(): SyntaxRegistry
    {
        return $this->registry;
    }

    /**
     * Очищает кэш. Возвращает количество удалённых файлов.
     */
    public function flush(): int
    {
        return $this->cache->flush();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function compile(string $source): array
    {
        $source = preg_replace('~^\xEF\xBB\xBF~', '', $source) ?? $source;
        $lines = preg_split('~\r\n|\n|\r~', $source) ?: [];

        $context = new Context($this->config->modes->html, $this->registry, $this->renderer);

        return (new BlockParser())->parse($lines, $context);
    }

    /**
     * @param list<array<string, mixed>> $elements
     */
    private function output(array $elements)
    {
        return match ($this->config->format) {
            MarkdownTransfer::FORMAT_ARRAY => $elements,
            MarkdownTransfer::FORMAT_COLLECTION => new Collection($elements),
            default => $this->renderer->make(__DIR__ . '/resources/views/document.blade.php', [
                'html' => implode("\n", array_column($elements, 'html')),
                'elements' => new Collection($elements),
            ]),
        };
    }

    private function fingerprint(): string
    {
        $mode = $this->config->modes->html;

        return sha1(implode('|', [
            self::PACKAGE_VERSION,
            (int) $mode->escape,
            (int) $mode->sanitize,
            $this->registry->fingerprint(),
        ]));
    }
}
