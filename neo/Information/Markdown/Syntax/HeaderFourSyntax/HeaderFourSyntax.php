<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\HeaderFourSyntax;

use Neo\Information\Markdown\Syntax\Base\BasicHeaderSyntax;

/**
 * # ... — заголовок уровня 4. Шаблон: HeaderFourSyntax.blade.php
 */
class HeaderFourSyntax extends BasicHeaderSyntax
{
    protected static function level(): int
    {
        return 4;
    }
}
