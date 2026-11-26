<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Contracts;

use Neo\Information\Markdown\Engine\Context;
use Neo\Information\Markdown\Engine\Hit;
use Neo\Information\Markdown\Engine\Lines;

interface BlockSyntaxContract extends SyntaxContract
{
    /**
     * Вызывается на текущей строке курсора.
     * Вернуть Hit(сколько строк занято, атрибуты для Transfer) либо null.
     */
    public static function matchBlock(Lines $lines, Context $context): ?Hit;
}
