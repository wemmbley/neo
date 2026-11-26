<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\AudioSyntax;

use Neo\Information\Markdown\Syntax\Base\BasicMediaSyntax;

/**
 * Аудиофайл (.mp3 .wav .m4a …) строкой `![title](/audio/ep1.mp3)` или голым URL.
 */
class AudioSyntax extends BasicMediaSyntax
{
    public function __construct(
        public AudioSyntaxTransfer $config,
    ) {}

    public static function priority(): int
    {
        return 82;
    }

    protected static function mediaType(): string
    {
        return 'audio';
    }

    protected static function attributes(array $media, string $label, ?string $title, ?string $link): array
    {
        return ['src' => $media['src'], 'title' => $title ?? ($label !== '' ? $label : null)];
    }
}
