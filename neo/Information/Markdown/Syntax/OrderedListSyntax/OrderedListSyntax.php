<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\OrderedListSyntax;

use Neo\Information\Markdown\Engine\Context;
use Neo\Information\Markdown\Engine\Hit;
use Neo\Information\Markdown\Engine\Lines;
use Neo\Information\Markdown\Syntax\Base\BasicListSyntax;

/**
 * 1. пункт / 1) пункт
 */
class OrderedListSyntax extends BasicListSyntax
{
    public function __construct(
        public OrderedListSyntaxTransfer $config,
    ) {}

    protected static function marker(string $line): ?array
    {
        if (preg_match('~^( {0,3})(\d{1,9})([.)])([ \t]+)(\S.*)$~', $line, $m) !== 1) {
            return null;
        }

        return [
            'width' => strlen($m[1]) + strlen($m[2]) + 1 + min(4, max(1, strlen($m[4]))),
            'text' => $m[5],
            'number' => (int) $m[2],
        ];
    }

    public static function matchBlock(Lines $lines, Context $context): ?Hit
    {
        $list = static::collect($lines, $context);

        return $list === null
            ? null
            : new Hit($list['length'], ['items' => $list['items'], 'start' => $list['start'] ?? 1]);
    }
}
