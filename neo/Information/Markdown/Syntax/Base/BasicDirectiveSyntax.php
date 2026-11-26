<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\Base;

use Neo\Information\Markdown\Contracts\BlockSyntaxContract;
use Neo\Information\Markdown\Engine\Context;
use Neo\Information\Markdown\Engine\Hit;
use Neo\Information\Markdown\Engine\Lines;
use Neo\Information\Markdown\Exceptions\MarkdownException;
use Neo\Information\Markdown\Syntax\BasicSyntax;

/**
 * Директива: `::имя{ключ="значение" ключ2='значение' ключ3=значение}` отдельной строкой.
 * Имя берётся из name() синтаксиса, атрибуты уходят в его Transfer (маппинг + валидация).
 */
abstract class BasicDirectiveSyntax extends BasicSyntax implements BlockSyntaxContract
{
    private const ATTRIBUTE = '~([A-Za-z_][\w-]*)\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'|([^\s"\'}]+))~';

    public static function priority(): int
    {
        return 20;
    }

    /**
     * Дополнительная проверка атрибутов (например, безопасность URL).
     * false — строка остаётся обычным текстом.
     *
     * @param array<string, string> $attributes
     */
    protected static function accepts(array $attributes, Context $context): bool
    {
        return true;
    }

    public static function matchBlock(Lines $lines, Context $context): ?Hit
    {
        $pattern = '~^ {0,3}::' . preg_quote(static::name(), '~') . '\{(.*)\}[ \t]*$~i';

        if (preg_match($pattern, $lines->current(), $m) !== 1) {
            return null;
        }

        $attributes = self::parse($m[1], static::name(), $lines->number());

        return static::accepts($attributes, $context) ? new Hit(1, $attributes) : null;
    }

    /**
     * @return array<string, string>
     */
    private static function parse(string $raw, string $name, int $line): array
    {
        $leftover = preg_replace(self::ATTRIBUTE, '', $raw);

        if ($leftover === null || trim($leftover) !== '') {
            throw MarkdownException::malformedDirective($name, $line);
        }

        preg_match_all(self::ATTRIBUTE, $raw, $all, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        $attributes = [];

        foreach ($all as $match) {
            $value = $match[2] ?? $match[3] ?? $match[4] ?? '';

            if ($match[4] === null) {
                $value = (string) preg_replace('~\\\\(["\'\\\\])~', '$1', $value);
            }

            $attributes[str_replace('-', '_', $match[1])] = $value;
        }

        return $attributes;
    }
}
