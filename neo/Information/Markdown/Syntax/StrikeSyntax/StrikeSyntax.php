<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\StrikeSyntax;

use Neo\Information\Markdown\Syntax\Base\BasicDelimitedSyntax;

/**
 * ~~зачёркнутый~~
 */
class StrikeSyntax extends BasicDelimitedSyntax
{
    public static function priority(): int
    {
        return 45;
    }

    protected static function delimiters(): array
    {
        return ['~~'];
    }
}
