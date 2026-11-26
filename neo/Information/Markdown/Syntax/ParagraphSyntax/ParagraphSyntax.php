<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\ParagraphSyntax;

use Neo\Information\Markdown\Syntax\Base\TextTransfer;
use Neo\Information\Markdown\Syntax\BasicSyntax;

/**
 * Абзац: всё, что не забрал ни один блочный синтаксис.
 * Не сопоставляется сам — парсер обращается к нему по имени `paragraph`.
 */
class ParagraphSyntax extends BasicSyntax
{
    public function __construct(
        public TextTransfer $config,
    ) {}

    public static function priority(): int
    {
        return 1000;
    }
}
