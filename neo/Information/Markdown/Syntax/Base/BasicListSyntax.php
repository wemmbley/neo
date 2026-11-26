<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\Base;

use Neo\Information\Markdown\Contracts\BlockSyntaxContract;
use Neo\Information\Markdown\Engine\Context;
use Neo\Information\Markdown\Engine\Lines;
use Neo\Information\Markdown\Engine\Text;
use Neo\Information\Markdown\Syntax\BasicSyntax;
use Neo\Information\Markdown\Syntax\DividerSyntax\DividerSyntax;

/**
 * Разбор списков. Пункт — это вложенный блочный markdown (абзацы, вложенные списки, код),
 * поэтому в blade приходит готовый HTML каждого пункта.
 */
abstract class BasicListSyntax extends BasicSyntax implements BlockSyntaxContract
{
    /**
     * Разбирает маркер пункта.
     *
     * @return array{width: int, text: string, number: ?int}|null width — колонка, с которой начинается содержимое
     */
    abstract protected static function marker(string $line): ?array;

    public static function priority(): int
    {
        return 70;
    }

    /**
     * @return array{length: int, items: list<string>, start: ?int}|null
     */
    protected static function collect(Lines $lines, Context $context): ?array
    {
        $first = static::marker($lines->current());

        if ($first === null) {
            return null;
        }

        $items = [];
        $n = 0;

        while (($line = $lines->peek($n)) !== null) {
            if (Text::blank($line)) {
                // Пустые строки между пунктами не разрывают список.
                $k = $n + 1;

                while (($x = $lines->peek($k)) !== null && Text::blank($x)) {
                    $k++;
                }

                $next = $lines->peek($k);

                if ($next !== null && static::marker($next) !== null && preg_match(DividerSyntax::PATTERN, $next) !== 1) {
                    $n = $k;
                    continue;
                }

                break;
            }

            $marker = preg_match(DividerSyntax::PATTERN, $line) === 1 ? null : static::marker($line);

            if ($marker === null) {
                break;
            }

            $body = [$marker['text']];
            $startLine = $lines->number($n);
            $n++;

            while (($l = $lines->peek($n)) !== null) {
                if (Text::blank($l)) {
                    $k = $n + 1;

                    while (($x = $lines->peek($k)) !== null && Text::blank($x)) {
                        $k++;
                    }

                    $next = $lines->peek($k);

                    if ($next !== null && Text::indent($next) >= $marker['width']) {
                        for (; $n < $k; $n++) {
                            $body[] = '';
                        }

                        continue;
                    }

                    break;
                }

                if (Text::indent($l) >= $marker['width']) {
                    $body[] = Text::outdent($l, $marker['width']);
                    $n++;
                    continue;
                }

                break;
            }

            $items[] = self::item($body, $startLine, $context);
        }

        return ['length' => $n, 'items' => $items, 'start' => $first['number']];
    }

    /**
     * Пункт из одного абзаца выводится без <p>.
     *
     * @param list<string> $body
     */
    private static function item(array $body, int $line, Context $context): string
    {
        $elements = $context->blocks($body, $line - 1);

        if (count($elements) === 1 && $elements[0]['type'] === 'paragraph') {
            return (string) ($elements[0]['data']['content'] ?? $elements[0]['html']);
        }

        return implode("\n", array_column($elements, 'html'));
    }
}
