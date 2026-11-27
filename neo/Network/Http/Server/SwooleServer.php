<?php

declare(strict_types=1);

namespace Neo\Network\Http\Server;

use Neo\Foundation\Runtime\Lifecycle;
use Neo\Foundation\Runtime\Runtime;
use Neo\Foundation\Runtime\Swoole\SwooleApi;
use Neo\Foundation\Runtime\Swoole\SwooleRuntime;
use Neo\Foundation\Runtime\Swoole\SwooleTaskDispatcher;
use Neo\Foundation\Runtime\Tasks\TaskRunner;
use Neo\Network\Http\FileResponse;
use Neo\Network\Http\HttpKernel;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use RuntimeException;
use Throwable;

/**
 * Необязательный ускоритель: HTTP-сервер на Swoole или OpenSwoole. Весь движок работает и без него
 * (классический SAPI через public/index.php, тот же HttpKernel); этот класс подключается ТОЛЬКО
 * командой `php cli serve --swoole` и требует расширение swoole/openswoole.
 *
 * Жизненный цикл (docs/Specifications/Runtime Specification.md):
 *
 *   master:  загружает приложение один раз (Kernel, роутер, конфиг), форкает воркеры
 *   worker:  workerStart  → Runtime::use(SwooleRuntime), Lifecycle::workerStart() (пулы, снимки)
 *   request: одна корутина на запрос → HttpKernel::serve() → Lifecycle::requestEnd() в finally
 *   worker:  workerStop   → Lifecycle::workerStop() (закрыть пулы и соединения), graceful
 *
 * Изоляция запросов:
 *  - RequestContext хранит запрос, ответ и scoped-сервисы в контексте корутины;
 *  - Router, MiddlewareRegistry, Cookie/Session-менеджеры не хранят данных запроса в свойствах;
 *  - соединения БД берутся из пула воркера и не делятся между одновременными запросами;
 *  - состояние, пережившее запрос, сбрасывается через Lifecycle (Resettable);
 *  - исключение (и `exit()`, который в корутине превращается в исключение) завершает только запрос.
 *
 * Хуки ввода-вывода: по умолчанию включён безопасный набор: сеть, sleep, curl, потоки и PDO-драйверы,
 * кроме файловых операций. Файловый кэш и файловые сессии приложения не рассчитаны на то, что
 * чтение-запись одного файла приостановится посреди операции. Набор меняется `hook_flags`
 * (0 = хуки выключены, ввод-вывод блокирует воркер, как раньше).
 *
 * Код этого файла не проверен на живом Swoole (в среде разработки нет расширения): он написан по
 * документации Swoole\Http\Server и OpenSwoole\HTTP\Server, логика покрыта тестами на заглушках.
 */
final class SwooleServer implements ServerContract
{
    private ?object $server = null;

    /** Воркер уже остановлен (workerExit и workerStop могут прийти оба). */
    private bool $stopped = false;

    /**
     * @param array{host?: string, port?: int, workers?: int, max_request?: int, max_wait_time?: int, request_timeout?: float|int, task_workers?: int, task_max_request?: int, mode?: string, backlog?: int, hook_flags?: int|null, ssl_cert?: string, ssl_key?: string} $options
     */
    public function __construct(
        private readonly HttpKernel $http,
        private readonly array $options = [],
        private readonly ?Lifecycle $lifecycle = null,
    ) {}

    public function start(): void
    {
        if (!SwooleApi::available()) {
            throw new RuntimeException('Расширение swoole не установлено. Движок работает и без него: запустите `php cli serve` без --swoole.');
        }

        $ssl = isset($this->options['ssl_cert'], $this->options['ssl_key']);

        $class = SwooleApi::cls('Http\Server');

        $this->server = new $class(
            $this->options['host'] ?? '127.0.0.1',
            (int) ($this->options['port'] ?? 8000),
            $this->mode(),
            $ssl ? SwooleApi::constant('SOCK_TCP') | SwooleApi::constant('SSL') : SwooleApi::constant('SOCK_TCP'),
        );

        $this->server->set($this->settings($ssl));

        $this->server->on('workerStart', fn (object $server, int $workerId) => $this->workerStart());
        # workerExit приходит, пока цикл событий ещё жив и запросы идут (reload_async, max_request): пулы тут не закрываем,
        # только снимаем таймеры. workerStop приходит после него, когда запросов уже нет, уже без корутин.
        $this->server->on('workerExit', fn (object $server, int $workerId) => $this->workerExit());
        $this->server->on('workerStop', fn (object $server, int $workerId) => $this->workerStop());
        $this->server->on('request', fn (object $request, object $response) => $this->handle($request, $response, $ssl));

        if ($this->taskWorkers() > 0) {
            $this->server->on('task', fn (object $server, object $task) => $this->task($task));
            $this->server->on('finish', static fn () => null);
        }

        $this->server->start();
    }

    public function stop(): void
    {
        $this->server?->shutdown();
    }

    /** @return array<string, mixed> */
    public function settings(bool $ssl = false): array
    {
        $settings = [
            'worker_num' => $this->options['workers'] ?? SwooleApi::cpuNum(),
            'max_request' => $this->options['max_request'] ?? 100000,
            'enable_coroutine' => true,
            'hook_flags' => $this->options['hook_flags'] ?? self::safeHookFlags(),
            'http_compression' => false,
            'package_max_length' => 16 * 1024 * 1024,

            # Без задержки Nagle маленькие HTTP-ответы уходят сразу, а не ждут до 40 мс.
            'open_tcp_nodelay' => true,
            'backlog' => (int) ($this->options['backlog'] ?? 2048),
            'log_level' => SwooleApi::constantOr('LOG_WARNING', 4),

            # Graceful: при остановке и перезапуске воркер дорабатывает текущие запросы.
            'reload_async' => true,
            'max_wait_time' => $this->options['max_wait_time'] ?? 30,
        ];

        if ($this->taskWorkers() > 0) {
            $settings['task_worker_num'] = $this->taskWorkers();
            $settings['task_enable_coroutine'] = true;
            $settings['task_max_request'] = $this->options['task_max_request'] ?? 1000;
        }

        if ($ssl) {
            $settings['ssl_cert_file'] = $this->options['ssl_cert'];
            $settings['ssl_key_file'] = $this->options['ssl_key'];
        }

        return $settings;
    }

    /**
     * Все хуки, кроме файловых и sqlite: файловый кэш/сессии работают блокирующе и потому атомарно,
     * а sqlite-драйвер всё равно блокирующий.
     */
    public static function safeHookFlags(): int
    {
        $flags = SwooleApi::constant('HOOK_ALL');

        foreach (['HOOK_FILE', 'HOOK_STDIO', 'HOOK_SQLITE', 'HOOK_PDO_SQLITE'] as $name) {
            foreach (['SWOOLE_', 'OPENSWOOLE_'] as $prefix) {
                if (defined($prefix . $name)) {
                    $flags &= ~(int) constant($prefix . $name);
                }
            }
        }

        return $flags;
    }

    /**
     * process: master раздаёт соединения воркерам (по умолчанию, поддерживает task workers).
     * base:    воркеры сами принимают соединения (reuse_port), нет передачи между процессами:
     *          на коротких запросах это заметно быстрее. С task workers недоступен.
     */
    private function mode(): int
    {
        $base = strtolower((string) ($this->options['mode'] ?? 'process')) === 'base' && $this->taskWorkers() === 0;

        return SwooleApi::constantOr($base ? 'BASE' : 'PROCESS', $base ? 1 : 2);
    }

    private function taskWorkers(): int
    {
        return max(0, (int) ($this->options['task_workers'] ?? 0));
    }

    private function workerStart(): void
    {
        $runtime = new SwooleRuntime();

        # Только воркеры запросов отправляют задачи: свой сервер им нужен для $server->task().
        $runtime->attachTaskServer($this->taskWorkers() > 0 ? $this->server : null);
        Runtime::use($runtime);

        $this->lifecycle?->workerStart();
    }

    private function workerExit(): void
    {
        try {
            $this->lifecycle?->workerExit();
        } catch (Throwable $e) {
            error_log('[swoole] worker exit failed: ' . $e::class . ': ' . $e->getMessage());
        }
    }

    private function workerStop(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;

        $stop = function (): void {
            try {
                $this->lifecycle?->workerStop();
            } catch (Throwable $e) {
                error_log('[swoole] worker stop failed: ' . $e::class . ': ' . $e->getMessage());
            }
        };

        # Закрытие пулов не должно зависеть от корутины: если она есть, работаем в ней, иначе пробуем создать,
        # а при неудаче закрываем напрямую (Pool::close() каналы не использует).
        if (SwooleApi::inCoroutine() || SwooleApi::create($stop) === 0) {
            $stop();
        }
    }

    private function handle(object $swooleRequest, object $swooleResponse, bool $ssl): void
    {
        $started = false;
        $timedOut = false;
        $timer = 0;

        try {
            $request = $this->makeRequest($swooleRequest, $ssl);
            $timeout = (float) ($this->options['request_timeout'] ?? 0);

            # Таймаут без дочерней корутины и канала на каждый запрос: один таймер на запрос, он же
            # отвечает клиенту 504 и отменяет корутину запроса. Если ответ уже начали отправлять
            # (в том числе потоковый), таймер ничего не делает: поток не обрывается на середине.
            if ($timeout > 0 && SwooleApi::inCoroutine()) {
                $cid = SwooleApi::cid();

                $timer = SwooleApi::after($timeout, function () use (&$started, &$timedOut, $cid, $swooleResponse): void {
                    if ($started) {
                        return;
                    }

                    $timedOut = true;
                    error_log('[swoole] request timeout');

                    if ($swooleResponse->isWritable()) {
                        $swooleResponse->status(504);
                        $swooleResponse->end('Gateway Timeout');
                    }

                    SwooleApi::cancel($cid);
                });
            }

            $this->http->serve($request, function (Response $response) use ($swooleResponse, &$started, &$timedOut): void {
                if ($timedOut) {
                    return; # клиенту уже ушёл 504, поздний ответ не отправляем
                }

                $started = true;
                $this->emit($response, $swooleResponse);
            });
        } catch (Throwable $e) {
            # Отмена по таймауту тоже приходит исключением из прерванного ввода-вывода: клиент уже получил 504.
            if (!$timedOut) {
                error_log('[swoole] ' . $e::class . ': ' . $e->getMessage());

                if ($swooleResponse->isWritable()) {
                    $swooleResponse->status(500);
                    $swooleResponse->end('Server Error');
                }
            }
        } finally {
            if ($timer > 0) {
                SwooleApi::clearTimer($timer);
            }

            # Состояние запроса сбрасывается всегда, даже при ошибке.
            $this->lifecycle?->requestEnd();
        }
    }

    /** Задача из очереди task workers: выполняется в их процессе, ошибка логируется и не роняет воркер. */
    private function task(object $task): void
    {
        $data = $task->data;

        try {
            if (is_array($data) && isset($data[SwooleTaskDispatcher::KEY_TASK])) {
                TaskRunner::run((string) $data[SwooleTaskDispatcher::KEY_TASK], (array) ($data[SwooleTaskDispatcher::KEY_PAYLOAD] ?? []));
            }
        } finally {
            $this->lifecycle?->requestEnd();

            if (method_exists($task, 'finish')) {
                $task->finish(true);
            }
        }
    }

    private function makeRequest(object $request, bool $ssl): Request
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

    private function emit(Response $response, object $out): void
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
