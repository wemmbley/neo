<?php

declare(strict_types=1);

namespace Neo\Network\Http;

use Neo\Network\Http\Exceptions\NotFoundHttpException;

/**
 * Отдаёт файл браузеру с его MIME-типом (например PDF или картинку).
 *
 * Безопасность: путь передаёт РАЗРАБОТЧИК. Никогда не подставляйте сюда необработанный ввод
 * пользователя (используйте Storage, он защищает от path traversal). Опасные для показа
 * в браузере типы (HTML, SVG, XML) по умолчанию отдаются как скачивание, иначе загруженный
 * пользователем файл превращается в XSS на вашем домене.
 */
class FileResponse extends Response
{
    /** MIME-типы, исполняющие скрипты при открытии в браузере. */
    private const array ACTIVE_TYPES = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/xml', 'application/xml', 'text/javascript',
    ];

    protected readonly string $path;

    public function __construct(
        string $path,
        int $status = 200,
        array $headers = [],
        ?string $disposition = null,
        ?string $name = null,
    ) {
        $real = realpath($path);

        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new NotFoundHttpException('Файл не найден.');
        }

        parent::__construct('', $status, $headers);

        $this->path = $real;

        $mime = $this->headers->get('Content-Type') ?? ((new \finfo(FILEINFO_MIME_TYPE))->file($real) ?: 'application/octet-stream');

        if (!$this->headers->has('Content-Type')) {
            $this->headers->set('Content-Type', $mime);
        }

        $disposition ??= in_array(strtolower(explode(';', $mime)[0]), self::ACTIVE_TYPES, true) ? 'attachment' : 'inline';

        $this->headers->set('Content-Disposition', self::disposition($disposition, $name ?? basename($real)));
        $this->headers->set('Content-Length', (string) filesize($real));
        $this->headers->set('Last-Modified', gmdate('D, d M Y H:i:s', (int) filemtime($real)) . ' GMT');
        $this->headers->set('X-Content-Type-Options', 'nosniff');
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isStreamed(): bool
    {
        return true;
    }

    public function getContent(): string
    {
        return '';
    }

    public function streamTo(callable $write): void
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            return;
        }

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);

                if ($chunk === false) {
                    break;
                }

                $write($chunk);
            }
        } finally {
            fclose($handle);
        }
    }

    public function prepare(Request $request): static
    {
        $length = $this->headers->get('Content-Length');

        parent::prepare($request);

        if ($length !== null && !$this->isEmpty()) {
            $this->headers->set('Content-Length', $length);
        }

        return $this;
    }

    /**
     * Content-Disposition по RFC 6266: ASCII-запас в filename и UTF-8 в filename*.
     * Кавычки, слэши и управляющие символы из имени удаляются.
     */
    protected static function disposition(string $type, string $name): string
    {
        $type = strtolower($type) === 'inline' ? 'inline' : 'attachment';
        $clean = (string) preg_replace('/[\x00-\x1f\x7f"\\\\\/%]+/u', '_', $name);
        $clean = $clean === '' ? 'file' : $clean;
        $ascii = (string) preg_replace('/[^\x20-\x7e]/', '_', $clean);

        $value = $type . '; filename="' . $ascii . '"';

        if ($ascii !== $clean) {
            $value .= "; filename*=UTF-8''" . rawurlencode($clean);
        }

        return $value;
    }
}
