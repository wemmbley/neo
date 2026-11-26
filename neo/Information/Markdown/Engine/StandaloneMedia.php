<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Engine;

/**
 * Строка, целиком состоящая из картинки/медиа:
 *   ![alt](url "title")  |  [![alt](url)](href)  |  https://голый-url  (отдельным абзацем)
 */
final class StandaloneMedia
{
    /**
     * @return array{form: string, url: string, label: string, title: ?string, link: ?string}|null
     */
    public static function find(Lines $lines): ?array
    {
        $line = trim($lines->current());

        if (str_starts_with($line, '![')) {
            $found = LinkScanner::scan($line, 1);

            if ($found === null || 1 + $found['length'] !== strlen($line)) {
                return null;
            }

            return [
                'form' => 'image',
                'url' => $found['url'],
                'label' => LinkScanner::plain($found['label']),
                'title' => $found['title'],
                'link' => null,
            ];
        }

        if (str_starts_with($line, '[![')) {
            $outer = LinkScanner::scan($line, 0);

            if ($outer === null || $outer['length'] !== strlen($line) || !str_starts_with($outer['label'], '![')) {
                return null;
            }

            $inner = LinkScanner::scan($outer['label'], 1);

            if ($inner === null || 1 + $inner['length'] !== strlen($outer['label'])) {
                return null;
            }

            return [
                'form' => 'image',
                'url' => $inner['url'],
                'label' => LinkScanner::plain($inner['label']),
                'title' => $inner['title'],
                'link' => $outer['url'],
            ];
        }

        if (
            $lines->standalone()
            && $lines->nextBlank()
            && preg_match('~^<?(https?://[^\s<>]+)>?$~i', $line, $m) === 1
        ) {
            return ['form' => 'url', 'url' => $m[1], 'label' => '', 'title' => null, 'link' => null];
        }

        return null;
    }
}
