<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\ItalicSyntax;

use Neo\Information\Markdown\Syntax\Base\BasicDelimitedSyntax;

/**
 * *курсив* и _курсив_
 */
class ItalicSyntax extends BasicDelimitedSyntax
{
    public static function priority(): int
    {
        return 50;
    }

    protected static function delimiters(): array
    {
        return ['*', '_'];
    }
}
