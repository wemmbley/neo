<?php

declare(strict_types=1);

use Neo\Information\Markdown\MarkdownManager;
use Neo\Information\Markdown\Syntax\VideoSyntax\VideoSyntax;
use Neo\Information\Markdown\Transfer\MarkdownTransfer;

/**
 * Конфиг для тестов: формат array, без кэша, строгие режимы.
 *
 * @param array<string, mixed> $overrides
 */
function markdownConfig(array $overrides = []): MarkdownTransfer
{
    $syntax = $overrides['syntax'] ?? [VideoSyntax::class];
    unset($overrides['syntax']);

    return new MarkdownTransfer(array_replace_recursive([
        'format' => 'array',
        'modes' => ['html' => ['escape' => true, 'sanitize' => true]],
        'cache' => ['enabled' => false],
    ], $overrides) + ['syntax' => $syntax]);
}

/**
 * @param array<string, mixed> $overrides
 */
function markdownManager(array $overrides = []): MarkdownManager
{
    return new MarkdownManager(markdownConfig($overrides));
}

/**
 * Разбирает строку и возвращает список элементов.
 *
 * @param array<string, mixed> $overrides
 * @return list<array<string, mixed>>
 */
function markdown(string $source, array $overrides = []): array
{
    return markdownManager($overrides + ['format' => 'array'])->parseString($source);
}

/**
 * HTML всего документа одной строкой.
 */
function markdownHtml(string $source, array $overrides = []): string
{
    return squish(implode("\n", array_column(markdown($source, $overrides), 'html')));
}

/**
 * HTML без нормализации пробелов (для проверки inline-вёрстки).
 */
function inlineHtml(string $source, array $overrides = []): string
{
    return implode("\n", array_column(markdown($source, $overrides), 'html'));
}

/**
 * Убирает пробелы между тегами: тесты не зависят от форматирования blade.
 */
function squish(string $html): string
{
    return trim((string) preg_replace('~>\s+<~', '><', $html));
}

/**
 * @param list<array<string, mixed>> $elements
 *
 * @return list<string>
 */
function types(array $elements): array
{
    return array_column($elements, 'type');
}

function markdownTempDir(): string
{
    $dir = sys_get_temp_dir() . '/neo-markdown-' . bin2hex(random_bytes(6));
    mkdir($dir, 0775, true);

    return $dir;
}
