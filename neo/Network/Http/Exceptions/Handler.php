<?php

declare(strict_types=1);

namespace Neo\Network\Http\Exceptions;

use Closure;
use Neo\Foundation\Kernel\ServiceContainer;
use Neo\Information\Logger\Managers\LogManager;
use Neo\Information\Persistence\ORM\Exceptions\ModelNotFoundException;
use Neo\Information\Validator\ValidationException;
use Neo\Network\Http\Contracts\ExceptionHandlerContract;
use Neo\Network\Http\JsonResponse;
use Neo\Network\Http\RedirectResponse;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Neo\Network\Http\Routing\UrlGenerator;
use Neo\Security\Xss\Escaper;
use Throwable;

/**
 * Единый обработчик исключений HTTP: превращает любое исключение в ответ.
 *
 * JSON отдаётся, если клиент ждёт JSON (Accept / XHR), иначе HTML-страница.
 * Подробности (класс, файл, трассировка) показываются только при `app.debug = true`.
 * В боевом режиме 5xx отдают общий текст: сообщения исключений могут содержать пути,
 * SQL и другие внутренности.
 */
class Handler implements ExceptionHandlerContract
{
    /** @var list<class-string<Throwable>> */
    protected array $dontReport = [
        ValidationException::class,
        ModelNotFoundException::class,
        HttpResponseException::class,
    ];

    /** @var list<Closure(Throwable, Request): ?Response> */
    protected array $renderers = [];

    /** @var list<Closure(Throwable): mixed> */
    protected array $reporters = [];

    public function __construct(protected readonly ServiceContainer $container) {}

    /**
     * Свой рендер для типа исключения: замыкание вида `fn (MyException $e, Request $r) => response()->json(...)`.
     * Рендеры опрашиваются по порядку, побеждает первый вернувший Response; $first ставит рендер в начало очереди.
     */
    public function renderable(Closure $renderer, bool $first = false): static
    {
        $first ? array_unshift($this->renderers, $renderer) : $this->renderers[] = $renderer;

        return $this;
    }

    public function reportable(Closure $reporter): static
    {
        $this->reporters[] = $reporter;

        return $this;
    }

    /** @param class-string<Throwable> ...$classes */
    public function dontReport(string ...$classes): static
    {
        array_push($this->dontReport, ...$classes);

        return $this;
    }

    public function report(Throwable $e): void
    {
        if ($this->shouldntReport($e)) {
            return;
        }

        foreach ($this->reporters as $reporter) {
            try {
                $reporter($e);
            } catch (Throwable) {
                # Репортёр не имеет права ронять обработку запроса.
            }
        }

        try {
            $this->container->make(LogManager::class)->report($e);
        } catch (Throwable) {
        }
    }

    protected function shouldntReport(Throwable $e): bool
    {
        if ($e instanceof HttpException && $e->getStatusCode() < 500) {
            return true;
        }

        foreach ($this->dontReport as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    public function render(Request $request, Throwable $e): Response
    {
        foreach ($this->renderers as $renderer) {
            $response = $this->callRenderer($renderer, $e, $request);

            if ($response instanceof Response) {
                return $response;
            }
        }

        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        if ($e instanceof ValidationException) {
            return $this->invalid($request, $e);
        }

        if ($e instanceof ModelNotFoundException) {
            $e = new NotFoundHttpException('', $e);
        }

        $status = $e instanceof HttpException ? $e->getStatusCode() : 500;
        $headers = $e instanceof HttpException ? $e->getHeaders() : [];

        return $request->expectsJson()
            ? $this->renderJson($e, $status, $headers)
            : $this->renderHtml($e, $status, $headers);
    }

    protected function callRenderer(Closure $renderer, Throwable $e, Request $request): mixed
    {
        $parameter = (new \ReflectionFunction($renderer))->getParameters()[0] ?? null;
        $type = $parameter?->getType();

        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && !$e instanceof ($type->getName())) {
            return null;
        }

        return $renderer($e, $request);
    }

    protected function invalid(Request $request, ValidationException $e): Response
    {
        # В getMessage() ValidationException дописан подробный список ошибок для разработчика;
        # клиенту отдаём только заголовок (первую строку), ошибки идут отдельным полем.
        $message = explode(PHP_EOL, $e->getMessage(), 2)[0];

        if ($request->expectsJson()) {
            return new JsonResponse(['message' => $message, 'errors' => $e->errors()], 422);
        }

        $redirect = (new RedirectResponse($this->container->make(UrlGenerator::class)->previous()))->setRequest($request);

        # Без сессии (маршрут вне группы web) вернуть пользователя с ошибками нечем: отвечаем 422.
        if (!$request->hasSession()) {
            return new Response($message, 422);
        }

        return $redirect->withInput()->withErrors($e->errors());
    }

    /** @param array<string, string> $headers */
    protected function renderJson(Throwable $e, int $status, array $headers): Response
    {
        $payload = ['message' => $this->message($e, $status)];

        if ($this->debug()) {
            $payload += [
                'exception' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => array_map(
                    static fn (array $frame): string => ($frame['file'] ?? '[internal]') . ':' . ($frame['line'] ?? '?'),
                    array_slice($e->getTrace(), 0, 20),
                ),
            ];
        }

        return new JsonResponse($payload, $status, $headers);
    }

    /** @param array<string, string> $headers */
    protected function renderHtml(Throwable $e, int $status, array $headers): Response
    {
        $title = $status . ' ' . (Response::PHRASES[$status] ?? 'Error');
        $message = Escaper::html($this->message($e, $status));

        $details = '';
        if ($this->debug()) {
            $details = '<h2>' . Escaper::html($e::class) . '</h2><p>' . Escaper::html($e->getFile() . ':' . $e->getLine())
                . '</p><pre style="overflow:auto;background:#f4f4f5;padding:12px;border-radius:8px">'
                . Escaper::html($e->getTraceAsString()) . '</pre>';
        }

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . Escaper::html($title) . '</title></head>'
            . '<body style="font-family:system-ui,sans-serif;max-width:720px;margin:12vh auto;padding:0 16px;color:#18181b">'
            . '<h1>' . Escaper::html($title) . '</h1>' . ($message !== '' ? '<p>' . $message . '</p>' : '') . $details . '</body></html>';

        return new Response($html, $status, $headers);
    }

    protected function message(Throwable $e, int $status): string
    {
        if ($e instanceof HttpException && $e->getMessage() !== '' && ($status < 500 || $this->debug())) {
            return $e->getMessage();
        }

        if ($status >= 500) {
            return $this->debug() ? $e->getMessage() : 'Server Error';
        }

        return Response::PHRASES[$status] ?? 'Error';
    }

    protected function debug(): bool
    {
        return (bool) config('app.debug', false);
    }
}
