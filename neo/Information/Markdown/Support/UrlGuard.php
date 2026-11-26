<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Support;

final class UrlGuard
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    // Растровые data-URI (как и в CommonMark). SVG и text/html сюда не входят.
    private const DATA_IMAGE = '~^data:image/(?:png|gif|jpe?g|webp);base64,[a-z0-9+/=]+$~i';

    /**
     * Безопасен ли URL для src/href: относительный, protocol-relative, http(s)
     * или растровый data:image. Всё остальное (javascript:, vbscript:, file:,
     * data:text/html, data:image/svg+xml ...) — нет.
     */
    public static function isSafe(string $url): bool
    {
        // Браузеры игнорируют пробелы и управляющие символы внутри схемы: "java\tscript:".
        $clean = preg_replace('~[\x00-\x20\x7F]+~', '', $url) ?? '';

        if ($clean === '') {
            return false;
        }

        if (preg_match(self::DATA_IMAGE, $clean) === 1) {
            return true;
        }

        if (preg_match('~^([a-z][a-z0-9+.\-]*):~i', $clean, $match) === 1) {
            return in_array(strtolower($match[1]), self::ALLOWED_SCHEMES, true);
        }

        return true;
    }
}
