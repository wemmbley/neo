<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Engine;

final class Text
{
    public static function blank(string $line): bool
    {
        return trim($line) === '';
    }

    /**
     * Ширина отступа в колонках (таб = до ближайшей кратной 4).
     */
    public static function indent(string $line): int
    {
        $width = 0;

        for ($i = 0, $n = strlen($line); $i < $n; $i++) {
            if ($line[$i] === ' ') {
                $width++;
            } elseif ($line[$i] === "\t") {
                $width += 4 - ($width % 4);
            } else {
                break;
            }
        }

        return $width;
    }

    /**
     * Убирает до $width колонок отступа.
     */
    public static function outdent(string $line, int $width): string
    {
        $taken = 0;
        $i = 0;

        for ($n = strlen($line); $i < $n && $taken < $width; $i++) {
            if ($line[$i] === ' ') {
                $taken++;
            } elseif ($line[$i] === "\t") {
                $taken += 4 - ($taken % 4);
            } else {
                break;
            }
        }

        return substr($line, $i);
    }
}
