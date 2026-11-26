<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\HeaderFiveSyntax;

use Neo\Information\Markdown\Syntax\Base\BasicHeaderSyntax;

/**
 * # ... — заголовок уровня 5. Шаблон: HeaderFiveSyntax.blade.php
 */
class HeaderFiveSyntax extends BasicHeaderSyntax
{
    protected static function level(): int
    {
        return 5;
    }
}
