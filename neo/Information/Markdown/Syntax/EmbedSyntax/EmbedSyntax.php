<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\EmbedSyntax;

use Neo\Information\Markdown\Syntax\Base\BasicMediaSyntax;

/**
 * Встраиваемое видео YouTube / Vimeo: `![title](https://youtu.be/…)` или голый URL отдельным абзацем.
 */
class EmbedSyntax extends BasicMediaSyntax
{
    public function __construct(
        public EmbedSyntaxTransfer $config,
    ) {}

    public static function priority(): int
    {
        return 80;
    }

    protected static function mediaType(): string
    {
        return 'embed';
    }

    protected static function attributes(array $media, string $label, ?string $title, ?string $link): array
    {
        return [
            'provider' => (string) $media['provider'],
            'id' => (string) $media['id'],
            'url' => $media['url'],
            'src' => $media['src'],
            'thumbnail' => $media['thumbnail'],
            'title' => $title ?? ($label !== '' ? $label : null),
        ];
    }
}
