<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\TableSyntax;

use Neo\Information\Markdown\Contracts\BlockSyntaxContract;
use Neo\Information\Markdown\Engine\Context;
use Neo\Information\Markdown\Engine\Hit;
use Neo\Information\Markdown\Engine\Lines;
use Neo\Information\Markdown\Engine\Text;
use Neo\Information\Markdown\Syntax\BasicSyntax;

/**
 * Таблица GFM:
 *   | a | b |
 *   |---|:-:|
 *   | 1 | 2 |
 */
class TableSyntax extends BasicSyntax implements BlockSyntaxContract
{
    public function __construct(
        public TableSyntaxTransfer $config,
    ) {}

    public static function priority(): int
    {
        return 60;
    }

    public static function matchBlock(Lines $lines, Context $context): ?Hit
    {
        $header = $lines->current();
        $delimiter = $lines->peek(1);

        if ($delimiter === null || !str_contains($header, '|') || !str_contains($delimiter, '|')) {
            return null;
        }

        $head = self::cells($header);
        $marks = self::cells($delimiter);

        if (count($head) !== count($marks)) {
            return null;
        }

        $align = [];

        foreach ($marks as $mark) {
            if (preg_match('~^(:?)-+(:?)$~', $mark, $m) !== 1) {
                return null;
            }

            $align[] = match (true) {
                $m[1] !== '' && $m[2] !== '' => 'center',
                $m[2] !== '' => 'right',
                $m[1] !== '' => 'left',
                default => null,
            };
        }

        $columns = count($head);
        $rows = [];

        for ($n = 2; ($line = $lines->peek($n)) !== null && !Text::blank($line) && str_contains($line, '|'); $n++) {
            $cells = array_slice(array_pad(self::cells($line), $columns, ''), 0, $columns);
            $rows[] = array_map(fn (string $cell): string => $context->inline($cell), $cells);
        }

        return new Hit($n, [
            'head' => array_map(fn (string $cell): string => $context->inline($cell), $head),
            'align' => $align,
            'rows' => $rows,
        ]);
    }

    /**
     * @return list<string>
     */
    private static function cells(string $row): array
    {
        $row = trim($row);

        if (str_starts_with($row, '|')) {
            $row = substr($row, 1);
        }

        if (str_ends_with($row, '|') && !str_ends_with($row, '\\|')) {
            $row = substr($row, 0, -1);
        }

        return array_map(
            fn (string $cell): string => trim(str_replace('\\|', '|', $cell)),
            preg_split('~(?<!\\\\)\|~', $row) ?: [],
        );
    }
}
