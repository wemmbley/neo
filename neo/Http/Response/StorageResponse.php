<?php

declare(strict_types=1);

namespace Neo\Http\Response;

/**
 * Отдать файл клиенту
 */
final class StorageResponse
{
    /**
     * @param array<string, string> $headers
     * @param resource              $stream
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public $stream,
    ) {
    }

    /**
     * Собирает безопасный заголовок Content-Disposition.
     * Вычищает кавычки, слеши и управляющие символы (защита от header injection)
     * и добавляет filename* для не-ASCII имён (RFC 5987).
     */
    public static function disposition(string $type, string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\\/]/', '', $name) ?? '';
        $name = $name === '' ? 'file' : $name;
        $ascii = preg_replace('/[^\x20-\x7E]|%/', '_', $name) ?? 'file';

        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $type, $ascii, rawurlencode($name));
    }

    /**
     * Отправляет ответ в клиент и закрывает поток.
     */
    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            // Любой перевод строки в заголовке - потенциальная инъекция.
            if (preg_match('/[\r\n]/', $name . $value) === 1) {
                continue;
            }

            header("{$name}: {$value}");
        }

        fpassthru($this->stream);
        fclose($this->stream);
    }
}