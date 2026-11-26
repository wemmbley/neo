<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\VideoFileSyntax;

use Neo\Information\Markdown\Syntax\Base\BasicMediaSyntax;

/**
 * Видеофайл (.mp4 .webm .mov …) строкой `![title](/media/demo.mp4)` или голым URL.
 */
class VideoFileSyntax extends BasicMediaSyntax
{
    public function __construct(
        public VideoFileSyntaxTransfer $config,
    ) {}

    public static function priority(): int
    {
        return 81;
    }

    protected static function mediaType(): string
    {
        return 'video';
    }

    protected static function attributes(array $media, string $label, ?string $title, ?string $link): array
    {
        return ['src' => $media['src'], 'title' => $title ?? ($label !== '' ? $label : null)];
    }
}
