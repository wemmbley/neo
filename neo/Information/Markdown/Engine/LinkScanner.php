<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Engine;

/**
 * Разбор `[label](destination "title")`. Используется ссылками, картинками и медиа.
 */
final class LinkScanner
{
    /**
     * @param int $pos позиция открывающей `[`
     *
     * @return array{label: string, url: string, title: ?string, length: int}|null length — от $pos
     */
    public static function scan(string $s, int $pos): ?array
    {
        $n = strlen($s);

        if (($s[$pos] ?? '') !== '[') {
            return null;
        }

        $depth = 0;

        for ($i = $pos; $i < $n; $i++) {
            $c = $s[$i];

            if ($c === '\\') {
                $i++;
                continue;
            }

            if ($c === '`') {
                $run = strspn($s, '`', $i);
                $close = strpos($s, str_repeat('`', $run), $i + $run);
                $i = $close === false ? $i + $run - 1 : $close + $run - 1;
                continue;
            }

            if ($c === '[') {
                $depth++;
            } elseif ($c === ']') {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            }
        }

        if ($i >= $n || $depth !== 0) {
            return null;
        }

        $label = substr($s, $pos + 1, $i - $pos - 1);
        $j = $i + 1;

        if (($s[$j] ?? '') !== '(') {
            return null;
        }

        $j += 1 + strspn($s, " \t\n", $j + 1);

        if (($s[$j] ?? '') === '<') {
            $end = strpos($s, '>', $j + 1);

            if ($end === false) {
                return null;
            }

            $dest = substr($s, $j + 1, $end - $j - 1);

            if (preg_match('~[\n<]~', $dest) === 1) {
                return null;
            }

            $j = $end + 1;
        } else {
            $start = $j;
            $parens = 0;

            while ($j < $n) {
                $c = $s[$j];

                if ($c === '\\' && $j + 1 < $n) {
                    $j += 2;
                    continue;
                }

                if (ord($c) <= 32) {
                    break;
                }

                if ($c === '(') {
                    $parens++;
                } elseif ($c === ')') {
                    if ($parens === 0) {
                        break;
                    }

                    $parens--;
                }

                $j++;
            }

            $dest = substr($s, $start, $j - $start);
        }

        if ($dest === '') {
            return null;
        }

        $j += strspn($s, " \t\n", $j);
        $title = null;
        $quote = $s[$j] ?? '';

        if ($quote === '"' || $quote === "'") {
            for ($k = $j + 1; $k < $n; $k++) {
                if ($s[$k] === '\\') {
                    $k++;
                    continue;
                }

                if ($s[$k] === $quote) {
                    break;
                }
            }

            if ($k >= $n) {
                return null;
            }

            $title = self::plain(substr($s, $j + 1, $k - $j - 1));
            $j = $k + 1;
            $j += strspn($s, " \t\n", $j);
        }

        if (($s[$j] ?? '') !== ')') {
            return null;
        }

        return [
            'label' => $label,
            'url' => self::plain($dest),
            'title' => $title === '' ? null : $title,
            'length' => $j + 1 - $pos,
        ];
    }

    /**
     * Убирает markdown-экранирование: `\*` => `*`.
     */
    public static function plain(string $value): string
    {
        return (string) preg_replace('#\\\\([!-/:-@\[-`{-~])#', '$1', $value);
    }
}
