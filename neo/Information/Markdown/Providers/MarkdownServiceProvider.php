<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Providers;

use Neo\Kernel\ServiceProvider;
use Neo\Information\Markdown\Contracts\TemplateRendererContract;
use Neo\Information\Markdown\MarkdownManager;
use Neo\Information\Markdown\Rendering\ViewRenderer;
use Neo\Information\Markdown\Syntax\SyntaxRegistry;
use Neo\Information\Markdown\Transfer\MarkdownTransfer;

class MarkdownServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MarkdownTransfer::class, fn (): MarkdownTransfer => new MarkdownTransfer($this->config()));

        // Реестр: встроенные синтаксисы + config['syntax'].
        $this->app->singleton(SyntaxRegistry::class, fn ($app): SyntaxRegistry => SyntaxRegistry::fromConfig(
            $app->make(MarkdownTransfer::class),
        ));

        $this->app->singleton(TemplateRendererContract::class, ViewRenderer::class);

        $this->app->singleton(MarkdownManager::class, fn ($app): MarkdownManager => new MarkdownManager(
            $app->make(MarkdownTransfer::class),
            $app->make(SyntaxRegistry::class),
            $app->make(TemplateRendererContract::class),
        ));

        $this->app->alias(MarkdownManager::class, 'markdown');

        // Менеджер создаётся сразу, чтобы фасад Markdown:: работал без make().
        // Синтаксисы, добавленные позже, попадают в тот же реестр.
        $this->app->make(MarkdownManager::class);
    }

    /**
     * Привязывает синтаксис к его Transfer и blade-шаблону.
     * Синтаксис с тем же name() заменяет существующий (в том числе встроенный).
     *
     *   $this->registerSyntax(VideoSyntax::class, VideoSyntaxTransfer::class, '/views/video.blade.php');
     *   $this->registerSyntax(HeaderOneSyntax::class, template: '/views/h1.blade.php');
     *
     * @param class-string<\Neo\Information\Markdown\Contracts\SyntaxContract> $syntax
     * @param class-string<\Neo\Transfer\Transfer>|null            $transfer null — Transfer по умолчанию
     * @param string|null                                          $template null — шаблон по умолчанию
     */
    public function registerSyntax(string $syntax, ?string $transfer = null, ?string $template = null): static
    {
        $this->app->make(SyntaxRegistry::class)->register($syntax, $transfer, $template);

        return $this;
    }

    /**
     * Убирает синтаксис (класс или имя, например 'header-six').
     */
    public function unregisterSyntax(string $syntaxOrName): static
    {
        $this->app->make(SyntaxRegistry::class)->forget($syntaxOrName);

        return $this;
    }

    /**
     * Конфиг пакета: значения по умолчанию + config('markdown').
     *
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        $defaults = require __DIR__ . '/../config/markdown.php';
        $custom = config('markdown') ?? [];

        $merged = array_replace_recursive($defaults, $custom);
        $merged['syntax'] = $custom['syntax'] ?? $defaults['syntax'];

        return $merged;
    }
}
