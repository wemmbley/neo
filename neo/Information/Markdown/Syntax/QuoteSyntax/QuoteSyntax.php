<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\QuoteSyntax;

use Neo\Information\Markdown\Contracts\BlockSyntaxContract;
use Neo\Information\Markdown\Engine\Context;
use Neo\Information\Markdown\Engine\Hit;
use Neo\Information\Markdown\Engine\Lines;
use Neo\Information\Markdown\Syntax\Base\ContentTransfer;
use Neo\Information\Markdown\Syntax\BasicSyntax;

/**
 * > цитата. Содержимое — вложенный блочный markdown.
 */
class QuoteSyntax extends BasicSyntax implements BlockSyntaxContract
{
    public function __construct(
        public ContentTransfer $config,
    ) {}

    public static function priority(): int
    {
        return 50;
    }

    public static function matchBlock(Lines $lines, Context $context): ?Hit
    {
        $inner = [];

        for ($n = 0; ($line = $lines->peek($n)) !== null; $n++) {
            if (preg_match('~^ {0,3}> ?(.*)$~', $line, $m) !== 1) {
                break;
            }

            $inner[] = $m[1];
        }

        if ($n === 0) {
            return null;
        }

        $elements = $context->blocks($inner, $lines->number() - 1);

        return new Hit($n, ['content' => implode("\n", array_column($elements, 'html'))]);
    }
}
