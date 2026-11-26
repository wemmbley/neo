<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Syntax\Base;

use Neo\Information\Markdown\Contracts\BlockSyntaxContract;
use Neo\Information\Markdown\Engine\Context;
use Neo\Information\Markdown\Engine\Hit;
use Neo\Information\Markdown\Engine\Lines;
use Neo\Information\Markdown\Engine\StandaloneMedia;
use Neo\Information\Markdown\Support\MediaDetector;
use Neo\Information\Markdown\Syntax\BasicSyntax;

/**
 * Строка-медиа: `![t](url)`, `[![t](img)](href)` или голый URL отдельным абзацем.
 * Какой синтаксис заберёт строку, решает тип по URL: embed / video / audio / image.
 */
abstract class BasicMediaSyntax extends BasicSyntax implements BlockSyntaxContract
{
    /**
     * @return string 'embed'|'video'|'audio'|'image'
     */
    abstract protected static function mediaType(): string;

    /**
     * @param array{type: string, url: string, src: string, provider: ?string, id: ?string, thumbnail: ?string} $media
     *
     * @return array<string, mixed>
     */
    abstract protected static function attributes(array $media, string $label, ?string $title, ?string $link): array;

    public static function matchBlock(Lines $lines, Context $context): ?Hit
    {
        $found = StandaloneMedia::find($lines);

        if ($found === null || !$context->safeUrl($found['url'])) {
            return null;
        }

        $media = MediaDetector::detect($found['url']);

        if ($media === null) {
            if ($found['form'] !== 'image') {
                return null;
            }

            $media = [
                'type' => 'image', 'url' => $found['url'], 'src' => $found['url'],
                'provider' => null, 'id' => null, 'thumbnail' => null,
            ];
        }

        if ($media['type'] !== static::mediaType()) {
            return null;
        }

        $link = $found['link'];

        if ($link !== null && ($media['type'] !== 'image' || !$context->safeUrl($link))) {
            if ($media['type'] !== 'image') {
                return null;
            }

            $link = null;
        }

        return new Hit(1, static::attributes($media, $found['label'], $found['title'], $link));
    }
}
