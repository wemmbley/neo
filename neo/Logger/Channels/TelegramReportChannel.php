<?php

declare(strict_types=1);

namespace Neo\Logger\Channels;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Отправляет краткое описание исключения в Telegram-чат.
 * Конфиг: ['channel' => 'telegram', 'token' => '...', 'chat_id' => '...', 'app' => 'MyApp', 'timeout' => 3]
 * Нужен allow_url_fopen = On.
 */
class TelegramReportChannel extends BasicReportChannel
{
    private const MAX_LENGTH = 4000; // лимит Telegram — 4096 символов

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        foreach (['token', 'chat_id'] as $key) {
            if (empty($config[$key])) {
                throw new InvalidArgumentException("Telegram channel requires '{$key}'.");
            }
        }

        parent::__construct($config);
    }

    public function send(Throwable $exception): void
    {
        $text = sprintf(
            "%s%s: %s\n%s:%d",
            isset($this->config['app']) ? "[{$this->config['app']}] " : '',
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        );

        $this->request('sendMessage', [
            'chat_id'                  => $this->config['chat_id'],
            'text'                     => mb_substr($text, 0, self::MAX_LENGTH),
            'disable_web_page_preview' => 'true',
        ]);
    }

    /** @param array<string, string> $payload */
    protected function request(string $method, array $payload): void
    {
        $context = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => http_build_query($payload),
            'timeout'       => (float) ($this->config['timeout'] ?? 3),
            'ignore_errors' => true,
        ]]);

        $url = "https://api.telegram.org/bot{$this->config['token']}/{$method}";
        $body = @file_get_contents($url, false, $context);
        $decoded = is_string($body) ? json_decode($body, true) : null;

        // Токен намеренно не попадает в текст ошибки.
        if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            throw new RuntimeException("Telegram API request '{$method}' failed.");
        }
    }
}