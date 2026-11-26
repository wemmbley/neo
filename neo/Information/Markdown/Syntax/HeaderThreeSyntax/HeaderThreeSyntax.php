<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\HeaderThreeSyntax;

use Neo\Information\Markdown\Syntax\Base\BasicHeaderSyntax;

/**
 * # ... — заголовок уровня 3. Шаблон: HeaderThreeSyntax.blade.php
 */
class HeaderThreeSyntax extends BasicHeaderSyntax
{
    protected static function level(): int
    {
        return 3;
    }
}
