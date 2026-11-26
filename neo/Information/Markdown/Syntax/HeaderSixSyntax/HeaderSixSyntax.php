<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\HeaderSixSyntax;

use Neo\Information\Markdown\Syntax\Base\BasicHeaderSyntax;

/**
 * # ... — заголовок уровня 6. Шаблон: HeaderSixSyntax.blade.php
 */
class HeaderSixSyntax extends BasicHeaderSyntax
{
    protected static function level(): int
    {
        return 6;
    }
}
