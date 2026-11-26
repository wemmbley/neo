<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Tests\Fixtures;

use Neo\Information\Markdown\Contracts\InlineSyntaxContract;
use Neo\Information\Markdown\Engine\Context;
use Neo\Information\Markdown\Engine\Hit;
use Neo\Information\Markdown\Syntax\Base\ContentTransfer;
use Neo\Information\Markdown\Syntax\BasicSyntax;

/**
 * Пример расширения: inline ==выделение==. Шаблон лежит рядом: HighlightSyntax.blade.php
 */
class HighlightSyntax extends BasicSyntax implements InlineSyntaxContract
{
    public function __construct(public ContentTransfer $config) {}

    public static function triggers(): string
    {
        return '=';
    }

    public static function matchInline(string $text, int $position, Context $context): ?Hit
    {
        if (preg_match('~\G==(\S(?:.*?\S)?)==~', $text, $m, 0, $position) !== 1) {
            return null;
        }

        return new Hit(strlen($m[0]), ['content' => $context->inline($m[1])]);
    }
}
