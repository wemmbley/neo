<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\InlineCodeSyntax;

use Neo\Information\Markdown\Contracts\InlineSyntaxContract;
use Neo\Information\Markdown\Engine\Context;
use Neo\Information\Markdown\Engine\Hit;
use Neo\Information\Markdown\Syntax\BasicSyntax;

/**
 * `код` и ``код с ` внутри``.
 */
class InlineCodeSyntax extends BasicSyntax implements InlineSyntaxContract
{
    public function __construct(
        public InlineCodeSyntaxTransfer $config,
    ) {}

    public static function priority(): int
    {
        return 10;
    }

    public static function triggers(): string
    {
        return '`';
    }

    public static function matchInline(string $text, int $position, Context $context): ?Hit
    {
        // Середина уже отвергнутой серии бэктиков.
        if ($position > 0 && $text[$position - 1] === '`') {
            return null;
        }

        $run = strspn($text, '`', $position);
        $marker = str_repeat('`', $run);
        $from = $position + $run;

        while (($at = strpos($text, $marker, $from)) !== false) {
            $end = $at + strspn($text, '`', $at);

            if ($end - $at === $run) {
                $code = str_replace("\n", ' ', substr($text, $from, $at - $from));

                if (strlen($code) > 2 && $code[0] === ' ' && str_ends_with($code, ' ') && trim($code) !== '') {
                    $code = substr($code, 1, -1);
                }

                return new Hit($end - $position, ['code' => $code]);
            }

            $from = $end;
        }

        return null;
    }
}
