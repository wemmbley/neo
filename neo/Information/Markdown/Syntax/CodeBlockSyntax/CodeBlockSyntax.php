<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\CodeBlockSyntax;

use Neo\Information\Markdown\Contracts\BlockSyntaxContract;
use Neo\Information\Markdown\Engine\Context;
use Neo\Information\Markdown\Engine\Hit;
use Neo\Information\Markdown\Engine\Lines;
use Neo\Information\Markdown\Syntax\BasicSyntax;

/**
 * ```lang … ``` и ~~~ … ~~~. Внутри ничего не интерпретируется.
 */
class CodeBlockSyntax extends BasicSyntax implements BlockSyntaxContract
{
    public function __construct(
        public CodeBlockSyntaxTransfer $config,
    ) {}

    public static function priority(): int
    {
        return 10;
    }

    public static function matchBlock(Lines $lines, Context $context): ?Hit
    {
        if (preg_match('#^ {0,3}(`{3,}|~{3,})[ \t]*([^\s`]*)[^`]*$#', $lines->current(), $m) !== 1) {
            return null;
        }

        $closing = '#^ {0,3}' . preg_quote($m[1][0], '#') . '{' . strlen($m[1]) . ',}[ \t]*$#';
        $body = [];

        for ($n = 1; ($line = $lines->peek($n)) !== null; $n++) {
            if (preg_match($closing, $line) === 1) {
                $n++;
                break;
            }

            $body[] = $line;
        }

        $lang = preg_replace('~[^\w+#.\-]~', '', $m[2]);

        return new Hit($n, [
            'lang' => $lang === null || $lang === '' ? null : $lang,
            'code' => implode("\n", $body),
        ]);
    }
}
