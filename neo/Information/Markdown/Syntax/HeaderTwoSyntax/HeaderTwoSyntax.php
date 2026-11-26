<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\HeaderTwoSyntax;

use Neo\Information\Markdown\Syntax\Base\BasicHeaderSyntax;

/**
 * # ... — заголовок уровня 2. Шаблон: HeaderTwoSyntax.blade.php
 */
class HeaderTwoSyntax extends BasicHeaderSyntax
{
    protected static function level(): int
    {
        return 2;
    }
}
