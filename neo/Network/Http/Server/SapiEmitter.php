<?php

declare(strict_types=1);

namespace Neo\Network\Http\Server;

use Neo\Network\Http\Response;

/**
 * Отправляет Response через классический SAPI: header(), echo (FPM, Apache, php -S).
 * Swoole-адаптер использует собственный способ отправки.
 */
final class SapiEmitter
{
    public function emit(Response $response): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');

            header(
                sprintf('HTTP/%s %d %s', $response->protocolVersion(), $response->status(), $response->statusText()),
                true,
                $response->status(),
            );

            foreach ($response->headers()->toArray() as $name => $values) {
                foreach (array_values($values) as $i => $value) {
                    header($name . ': ' . $value, $i === 0);
                }
            }

            foreach ($response->cookies() as $cookie) {
                header('Set-Cookie: ' . $cookie->toHeader(), false);
            }
        }

        if ($response->isHeadResponse() || $response->isEmpty()) {
            $this->finish();

            return;
        }

        if ($response->isStreamed()) {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $response->streamTo(static function (string $chunk): void {
                echo $chunk;
                flush();
            });
        } else {
            $response->streamTo(static function (string $chunk): void {
                echo $chunk;
            });
        }

        $this->finish();
    }

    /** Закрывает соединение с клиентом, а terminate() и отложенные задачи доработают уже без него (FPM). */
    private function finish(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }
}
