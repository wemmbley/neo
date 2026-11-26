<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\HtmlSyntax;

use Neo\Information\Markdown\Contracts\BlockSyntaxContract;
use Neo\Information\Markdown\Engine\Context;
use Neo\Information\Markdown\Engine\Hit;
use Neo\Information\Markdown\Engine\Lines;
use Neo\Information\Markdown\Engine\Text;
use Neo\Information\Markdown\Syntax\BasicSyntax;

/**
 * Блок сырого HTML (строка начинается с тега). Работает ТОЛЬКО при
 * modes.html.escape = false и modes.html.sanitize = false.
 * Иначе блок остаётся обычным текстом и экранируется/очищается.
 */
class HtmlSyntax extends BasicSyntax implements BlockSyntaxContract
{
    public function __construct(
        public HtmlSyntaxTransfer $config,
    ) {}

    public static function priority(): int
    {
        return 95;
    }

    public static function matchBlock(Lines $lines, Context $context): ?Hit
    {
        if ($context->mode->escape || $context->mode->sanitize) {
            return null;
        }

        if (preg_match('~^ {0,3}<(?:/?[A-Za-z][\w-]*(?:[\s/>]|$)|!--)~', $lines->current()) !== 1) {
            return null;
        }

        $html = [];

        for ($n = 0; ($line = $lines->peek($n)) !== null && !Text::blank($line); $n++) {
            $html[] = $line;
        }

        return new Hit($n, ['html' => implode("\n", $html)]);
    }
}
