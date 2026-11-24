<?php

declare(strict_types=1);

namespace Neo\Network\Http\Server;

use RuntimeException;
use Throwable;
use Neo\Network\Http\FileResponse;
use Neo\Network\Http\HttpKernel;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;

/**
 * Необязательный ускоритель: HTTP-сервер на Swoole. Весь движок работает и без него
 * (классический SAPI через public/index.php); этот класс подключается ТОЛЬКО командой
 * `php cli serve --swoole` и требует расширение swoole.
 *
 * Как сохраняется изоляция запросов:
 *  - каждый запрос Swoole выполняет в своей корутине; RequestContext хранит запрос,
 *    ответ и scoped-сервисы в контексте этой корутины;
 *  - Router, MiddlewareRegistry, Cookie/Session-менеджеры не хранят данных запроса в свойствах;
 *  - корутинные хуки ввода-вывода по умолчанию ВЫКЛЮЧЕНЫ (`hook_flags` = 0): PDO и файловый
 *    кэш приложения не рассчитаны на одновременное использование одного соединения из нескольких
 *    корутин. Включать хуки можно, только когда слой БД это поддерживает.
 *
 * Код этого файла не проверен в песочнице разработки (там нет расширения Swoole);
 * он написан по документации Swoole\Http\Server.
 */
final class SwooleServer implements ServerContract
{
    private ?\Swoole\Http\Server $server = null;

    /**
     * @param array{host?: string, port?: int, workers?: int, max_request?: int, hook_flags?: int, ssl_cert?: string, ssl_key?: string} $options
     */
    public function __construct(
        private readonly HttpKernel $http,
        private readonly array $options = [],
    ) {}

    public function start(): void
    {
        if (!extension_loaded('swoole') && !extension_loaded('openswoole')) {
            throw new RuntimeException('Расширение swoole не установлено. Движок работает и без него: запустите `php cli serve` без --swoole.');
        }

        $ssl = isset($this->options['ssl_cert'], $this->options['ssl_key']);

        $this->server = new \Swoole\Http\Server(
            $this->options['host'] ?? '127.0.0.1',
            (int) ($this->options['port'] ?? 8000),
            SWOOLE_PROCESS,
            $ssl ? SWOOLE_SOCK_TCP | SWOOLE_SSL : SWOOLE_SOCK_TCP,
        );

        $settings = [
            'worker_num' => $this->options['workers'] ?? swoole_cpu_num(),
            'max_request' => $this->options['max_request'] ?? 5000,
            'enable_coroutine' => true,
            'hook_flags' => $this->options['hook_flags'] ?? 0,
            'http_compression' => false,
            'package_max_length' => 16 * 1024 * 1024,
        ];

        if ($ssl) {
            $settings['ssl_cert_file'] = $this->options['ssl_cert'];
            $settings['ssl_key_file'] = $this->options['ssl_key'];
        }

        $this->server->set($settings);
        $this->server->on('request', fn ($request, $response) => $this->handle($request, $response, $ssl));
        $this->server->start();
    }

    public function stop(): void
    {
        $this->server?->shutdown();
    }

    private function handle(\Swoole\Http\Request $swooleRequest, \Swoole\Http\Response $swooleResponse, bool $ssl): void
    {
        try {
            $request = $this->makeRequest($swooleRequest, $ssl);

            $this->http->serve($request, fn (Response $response) => $this->emit($response, $swooleResponse));
        } catch (Throwable $e) {
            error_log('[swoole] ' . $e::class . ': ' . $e->getMessage());

            if ($swooleResponse->isWritable()) {
                $swooleResponse->status(500);
                $swooleResponse->end('Server Error');
            }
        }
    }

    private function makeRequest(\Swoole\Http\Request $request, bool $ssl): Request
    {
        $server = [];

        foreach ((array) $request->server as $key => $value) {
            $server[strtoupper((string) $key)] = $value;
        }

        $query = (string) ($server['QUERY_STRING'] ?? '');
        $server['REQUEST_URI'] = (string) ($server['REQUEST_URI'] ?? '/') . ($query !== '' ? '?' . $query : '');
        $server['HTTPS'] = $ssl ? 'on' : 'off';

        foreach ((array) $request->header as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', (string) $name))] = $value;
        }

        $content = (string) ($request->rawContent() ?: '');
        $post = (array) ($request->post ?? []);
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

        if ($post === [] && in_array($method, ['PUT', 'PATCH', 'DELETE'], true)
            && str_starts_with(strtolower((string) ($request->header['content-type'] ?? '')), 'application/x-www-form-urlencoded')
        ) {
            parse_str($content, $post);
        }

        return new Request(
            (array) ($request->get ?? []),
            $post,
            [],
            (array) ($request->cookie ?? []),
            (array) ($request->files ?? []),
            $server,
            $content,
        );
    }

    private function emit(Response $response, \Swoole\Http\Response $out): void
    {
        $out->status($response->status(), $response->statusText());

        foreach ($response->headers()->toArray() as $name => $values) {
            # Длину и способ передачи Swoole определяет сам.
            if (in_array(strtolower($name), ['content-length', 'transfer-encoding', 'connection'], true)) {
                continue;
            }

            $out->header($name, implode(', ', $values));
        }

        foreach ($response->cookies() as $cookie) {
            $out->rawCookie(
                $cookie->name,
                rawurlencode($cookie->value),
                $cookie->expires ?? 0,
                $cookie->path,
                $cookie->normalizedDomain() ?? '',
                $cookie->secure || strtolower((string) $cookie->sameSite) === 'none',
                $cookie->httpOnly,
                $cookie->sameSite !== null ? ucfirst(strtolower($cookie->sameSite)) : '',
            );
        }

        if ($response->isHeadResponse() || $response->isEmpty()) {
            $out->end();

            return;
        }

        if ($response instanceof FileResponse) {
            $out->sendfile($response->path());

            return;
        }

        if ($response->isStreamed()) {
            $response->streamTo(static function (string $chunk) use ($out): void {
                $out->write($chunk);
            });
            $out->end();

            return;
        }

        $out->end($response->getContent());
    }
}
