<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\BoldSyntax;

use Neo\Information\Markdown\Syntax\Base\BasicDelimitedSyntax;

/**
 * **жирный** и __жирный__
 */
class BoldSyntax extends BasicDelimitedSyntax
{
    public static function priority(): int
    {
        return 40;
    }

    protected static function delimiters(): array
    {
        return ['**', '__'];
    }
}
