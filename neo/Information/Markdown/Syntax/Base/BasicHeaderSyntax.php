<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\Base;

use Neo\Information\Markdown\Contracts\BlockSyntaxContract;
use Neo\Information\Markdown\Engine\Context;
use Neo\Information\Markdown\Engine\Hit;
use Neo\Information\Markdown\Engine\Lines;
use Neo\Information\Markdown\Syntax\BasicSyntax;

/**
 * `#` … `######`. Конкретный синтаксис задаёт только level().
 */
abstract class BasicHeaderSyntax extends BasicSyntax implements BlockSyntaxContract
{
    public function __construct(
        public HeaderSyntaxTransfer $config,
    ) {}

    abstract protected static function level(): int;

    public static function priority(): int
    {
        return 30;
    }

    public static function matchBlock(Lines $lines, Context $context): ?Hit
    {
        if (preg_match('~^ {0,3}(#{1,6})(?:[ \t]+(.*?))?[ \t]*$~', $lines->current(), $m) !== 1) {
            return null;
        }

        if (strlen($m[1]) !== static::level()) {
            return null;
        }

        $title = trim((string) preg_replace('~(?:^|[ \t]+)#+[ \t]*$~', '', $m[2] ?? ''));

        if ($title === '') {
            return null;
        }

        $content = $context->inline($title);
        $text = $context->plain($content);

        return new Hit(1, [
            'level' => static::level(),
            'content' => $content,
            'text' => $text,
            'id' => $context->slug($text !== '' ? $text : $title),
        ]);
    }
}
