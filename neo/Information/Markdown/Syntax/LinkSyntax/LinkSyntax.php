<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\LinkSyntax;

use Neo\Information\Markdown\Contracts\InlineSyntaxContract;
use Neo\Information\Markdown\Engine\Context;
use Neo\Information\Markdown\Engine\Hit;
use Neo\Information\Markdown\Engine\LinkScanner;
use Neo\Information\Markdown\Syntax\BasicSyntax;

/**
 * [текст](url "title"), <https://автоссылка> и голый https://адрес в тексте.
 */
class LinkSyntax extends BasicSyntax implements InlineSyntaxContract
{
    public function __construct(
        public LinkSyntaxTransfer $config,
    ) {}

    public static function priority(): int
    {
        return 30;
    }

    public static function triggers(): string
    {
        return '[<hH';
    }

    public static function matchInline(string $text, int $position, Context $context): ?Hit
    {
        // Ссылка внутри ссылки недопустима.
        if ($context->insideLink()) {
            return null;
        }

        return match ($text[$position]) {
            '[' => self::inline($text, $position, $context),
            '<' => self::angle($text, $position, $context),
            default => self::bare($text, $position, $context),
        };
    }

    private static function inline(string $text, int $position, Context $context): ?Hit
    {
        $found = LinkScanner::scan($text, $position);

        if ($found === null || !$context->safeUrl($found['url'])) {
            return null;
        }

        return new Hit($found['length'], [
            'url' => $found['url'],
            'content' => $context->inLink(fn (): string => $context->inline($found['label'])),
            'title' => $found['title'],
            'external' => preg_match('~^(?:https?:)?//~i', $found['url']) === 1,
        ]);
    }

    private static function angle(string $text, int $position, Context $context): ?Hit
    {
        if (preg_match('~\G<(https?://[^\s<>]+)>~i', $text, $m, 0, $position) !== 1 || !$context->safeUrl($m[1])) {
            return null;
        }

        return new Hit(strlen($m[0]), self::attributes($m[1], $context));
    }

    private static function bare(string $text, int $position, Context $context): ?Hit
    {
        if ($position > 0 && preg_match('~[A-Za-z0-9_]~', $text[$position - 1]) === 1) {
            return null;
        }

        if (preg_match('~\Ghttps?://[^\s<>]+~i', $text, $m, 0, $position) !== 1) {
            return null;
        }

        // Хвостовая пунктуация и лишняя `)` не входят в адрес.
        $url = rtrim($m[0], '.,:;!?*_~\'"');

        while (str_ends_with($url, ')') && substr_count($url, '(') < substr_count($url, ')')) {
            $url = rtrim(substr($url, 0, -1), '.,:;!?*_~\'"');
        }

        if (strlen($url) <= 8 || !$context->safeUrl($url)) {
            return null;
        }

        return new Hit(strlen($url), self::attributes($url, $context));
    }

    /**
     * @return array<string, mixed>
     */
    private static function attributes(string $url, Context $context): array
    {
        return ['url' => $url, 'content' => $context->text($url), 'title' => null, 'external' => true];
    }
}
