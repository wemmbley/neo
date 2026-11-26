<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\HeaderOneSyntax;

use Neo\Information\Markdown\Syntax\Base\BasicHeaderSyntax;

/**
 * # ... — заголовок уровня 1. Шаблон: HeaderOneSyntax.blade.php
 */
class HeaderOneSyntax extends BasicHeaderSyntax
{
    protected static function level(): int
    {
        return 1;
    }
}
