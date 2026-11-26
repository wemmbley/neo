<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Support;

/**
 * Определяет тип медиа по URL: image, video, audio или embed (YouTube, Vimeo).
 */
final class MediaDetector
{
    private const IMAGE = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp'];
    private const VIDEO = ['mp4', 'webm', 'mov', 'm4v', 'ogv'];
    private const AUDIO = ['mp3', 'wav', 'm4a', 'aac', 'flac', 'oga', 'ogg', 'opus'];

    /**
     * @return array{type: string, url: string, src: string, provider: ?string, id: ?string, thumbnail: ?string}|null
     */
    public static function detect(string $url): ?array
    {
        $url = trim($url);
        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');

        if ($host !== '' && ($embed = self::embed($host, $parts, $url)) !== null) {
            return $embed;
        }

        $extension = strtolower(pathinfo($parts['path'] ?? '', PATHINFO_EXTENSION));

        $type = match (true) {
            in_array($extension, self::IMAGE, true) => 'image',
            in_array($extension, self::VIDEO, true) => 'video',
            in_array($extension, self::AUDIO, true) => 'audio',
            default => null,
        };

        return $type === null ? null : [
            'type' => $type,
            'url' => $url,
            'src' => $url,
            'provider' => null,
            'id' => null,
            'thumbnail' => null,
        ];
    }

    /**
     * @param array<string, mixed> $parts
     * @return array{type: string, url: string, src: string, provider: ?string, id: ?string, thumbnail: ?string}|null
     */
    private static function embed(string $host, array $parts, string $url): ?array
    {
        $host = preg_replace('~^(www|m|music)\.~', '', $host) ?? $host;
        $path = (string) ($parts['path'] ?? '');

        if (in_array($host, ['youtube.com', 'youtube-nocookie.com', 'youtu.be'], true)) {
            $id = self::youtubeId($host, $path, (string) ($parts['query'] ?? ''));

            return $id === null ? null : [
                'type' => 'embed',
                'url' => $url,
                'src' => 'https://www.youtube-nocookie.com/embed/' . $id,
                'provider' => 'youtube',
                'id' => $id,
                'thumbnail' => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
            ];
        }

        if (in_array($host, ['vimeo.com', 'player.vimeo.com'], true)) {
            if (preg_match('~^/(?:video/|channels/[^/]+/|groups/[^/]+/videos/)?(\d+)~', $path, $match) !== 1) {
                return null;
            }

            return [
                'type' => 'embed',
                'url' => $url,
                'src' => 'https://player.vimeo.com/video/' . $match[1],
                'provider' => 'vimeo',
                'id' => $match[1],
                'thumbnail' => null,
            ];
        }

        return null;
    }

    private static function youtubeId(string $host, string $path, string $query): ?string
    {
        $id = null;

        if ($host === 'youtu.be') {
            $id = explode('/', ltrim($path, '/'))[0];
        } elseif ($path === '/watch') {
            parse_str($query, $params);
            $id = is_string($params['v'] ?? null) ? $params['v'] : null;
        } elseif (preg_match('~^/(?:embed|shorts|live|v)/([^/?]+)~', $path, $match) === 1) {
            $id = $match[1];
        }

        return $id !== null && preg_match('~^[A-Za-z0-9_-]{11}$~', $id) === 1 ? $id : null;
    }
}
