<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\ImageSyntax;

use Neo\Information\Markdown\Contracts\InlineSyntaxContract;
use Neo\Information\Markdown\Engine\Context;
use Neo\Information\Markdown\Engine\Hit;
use Neo\Information\Markdown\Engine\LinkScanner;
use Neo\Information\Markdown\Syntax\Base\BasicMediaSyntax;

/**
 * Картинка. Два режима с одним шаблоном (различает $el->block):
 *  - строка целиком (`![alt](src "title")`, `[![alt](src)](href)`, голый URL картинки) — блочный элемент;
 *  - внутри абзаца — inline.
 * Всё, что распознано как видео/аудио/embed, забирают VideoFileSyntax/AudioSyntax/EmbedSyntax.
 */
class ImageSyntax extends BasicMediaSyntax implements InlineSyntaxContract
{
    public function __construct(
        public ImageSyntaxTransfer $config,
    ) {}

    public static function priority(): int
    {
        return 90;
    }

    protected static function mediaType(): string
    {
        return 'image';
    }

    protected static function attributes(array $media, string $label, ?string $title, ?string $link): array
    {
        return [
            'src' => $media['src'],
            'alt' => $label,
            'title' => $title,
            'link' => $link,
            'block' => true,
        ];
    }

    public static function triggers(): string
    {
        return '!';
    }

    public static function matchInline(string $text, int $position, Context $context): ?Hit
    {
        if (($text[$position + 1] ?? '') !== '[') {
            return null;
        }

        $found = LinkScanner::scan($text, $position + 1);

        if ($found === null || !$context->safeUrl($found['url'])) {
            return null;
        }

        return new Hit(1 + $found['length'], [
            'src' => $found['url'],
            'alt' => LinkScanner::plain($found['label']),
            'title' => $found['title'],
            'link' => null,
            'block' => false,
        ]);
    }
}
