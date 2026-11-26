<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\BulletListSyntax;

use Neo\Information\Markdown\Engine\Context;
use Neo\Information\Markdown\Engine\Hit;
use Neo\Information\Markdown\Engine\Lines;
use Neo\Information\Markdown\Syntax\Base\BasicListSyntax;

/**
 * - пункт / * пункт / + пункт
 */
class BulletListSyntax extends BasicListSyntax
{
    public function __construct(
        public BulletListSyntaxTransfer $config,
    ) {}

    protected static function marker(string $line): ?array
    {
        if (preg_match('~^( {0,3})([-*+])([ \t]+)(\S.*)$~', $line, $m) !== 1) {
            return null;
        }

        return [
            'width' => strlen($m[1]) + 1 + min(4, max(1, strlen($m[3]))),
            'text' => $m[4],
            'number' => null,
        ];
    }

    public static function matchBlock(Lines $lines, Context $context): ?Hit
    {
        $list = static::collect($lines, $context);

        return $list === null ? null : new Hit($list['length'], ['items' => $list['items']]);
    }
}
