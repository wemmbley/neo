<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\DividerSyntax;

use Neo\Information\Markdown\Contracts\BlockSyntaxContract;
use Neo\Information\Markdown\Engine\Context;
use Neo\Information\Markdown\Engine\Hit;
use Neo\Information\Markdown\Engine\Lines;
use Neo\Information\Markdown\Syntax\BasicSyntax;

/**
 * --- , *** , ___ — горизонтальная линия.
 */
class DividerSyntax extends BasicSyntax implements BlockSyntaxContract
{
    public const PATTERN = '~^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$~';

    public function __construct(
        public DividerSyntaxTransfer $config,
    ) {}

    public static function priority(): int
    {
        return 40;
    }

    public static function matchBlock(Lines $lines, Context $context): ?Hit
    {
        return preg_match(self::PATTERN, $lines->current()) === 1 ? new Hit(1) : null;
    }
}
