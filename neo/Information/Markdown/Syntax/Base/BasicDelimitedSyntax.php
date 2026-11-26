<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\Base;

use Neo\Information\Markdown\Contracts\InlineSyntaxContract;
use Neo\Information\Markdown\Engine\Context;
use Neo\Information\Markdown\Engine\Hit;
use Neo\Information\Markdown\Syntax\BasicSyntax;

/**
 * Inline-выделение между одинаковыми разделителями: **жирный**, *курсив*, ~~зачёркнутый~~.
 * Конкретный синтаксис задаёт только delimiters().
 */
abstract class BasicDelimitedSyntax extends BasicSyntax implements InlineSyntaxContract
{
    public function __construct(
        public ContentTransfer $config,
    ) {}

    /**
     * @return list<string> например ['**', '__']
     */
    abstract protected static function delimiters(): array;

    public static function triggers(): string
    {
        return implode('', array_unique(array_map(fn (string $d): string => $d[0], static::delimiters())));
    }

    public static function matchInline(string $text, int $position, Context $context): ?Hit
    {
        foreach (static::delimiters() as $delimiter) {
            if (substr($text, $position, strlen($delimiter)) !== $delimiter) {
                continue;
            }

            $hit = self::scan($text, $position, $delimiter, $context);

            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    private static function scan(string $t, int $p, string $d, Context $context): ?Hit
    {
        $len = strlen($d);
        $char = $d[0];
        $n = strlen($t);

        // Одиночный разделитель не должен быть частью двойного: **x** — дело жирного.
        if ($len === 1 && strspn($t, $char, $p) !== 1) {
            return null;
        }

        // snake_case_слова не выделяются.
        if ($char === '_' && $p > 0 && ctype_alnum($t[$p - 1])) {
            return null;
        }

        $start = $p + $len;

        if ($start >= $n || ctype_space($t[$start])) {
            return null;
        }

        for ($i = $start; $i < $n;) {
            $c = $t[$i];

            if ($c === '\\') {
                $i += 2;
                continue;
            }

            if ($c === '`') {
                $run = strspn($t, '`', $i);
                $close = strpos($t, str_repeat('`', $run), $i + $run);
                $i = $close === false ? $i + $run : $close + $run;
                continue;
            }

            if ($c !== $char) {
                $i++;
                continue;
            }

            $run = strspn($t, $char, $i);
            $closes = $len === 1 ? ($run === 1 || $run === 3) : $run >= $len;

            if ($closes && $i > $start && !ctype_space($t[$i - 1])) {
                $closeAt = $i + $run - $len;
                $after = $closeAt + $len;
                $intraword = $char === '_' && isset($t[$after]) && ctype_alnum($t[$after]);
                $inner = substr($t, $start, $closeAt - $start);

                if (!$intraword && trim($inner) !== '') {
                    return new Hit($after - $p, ['content' => $context->inline($inner)]);
                }
            }

            $i += $run;
        }

        return null;
    }
}
