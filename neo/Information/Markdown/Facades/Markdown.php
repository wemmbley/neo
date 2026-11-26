<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Facades;

use LogicException;
use Neo\Information\Markdown\MarkdownManager;

/**
 * Статический доступ к MarkdownManager, созданному ранее (вручную или провайдером).
 *
 * @method static mixed parse(string $path)
 * @method static mixed parseString(string $markdown)
 * @method static MarkdownManager registerSyntax(string $syntax, ?string $transfer = null, ?string $template = null)
 * @method static MarkdownManager unregisterSyntax(string $syntaxOrName)
 * @method static \Neo\Information\Markdown\Syntax\SyntaxRegistry syntax()
 * @method static int flush()
 */
final class Markdown
{
    /**
     * @param array<int, mixed> $arguments
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        $manager = MarkdownManager::instance()
            ?? throw new LogicException('MarkdownManager is not initialised: new MarkdownManager(new MarkdownTransfer($config)).');

        return $manager->{$method}(...$arguments);
    }
}
