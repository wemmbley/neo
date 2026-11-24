<?php

declare(strict_types=1);

namespace Neo\Network\Session\Middleware;

use Closure;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;
use Neo\Network\Session\SessionManager;

/**
 * Открывает сессию на время запроса и сохраняет её перед отправкой ответа.
 * Куку сессии при сохранении кладёт SessionManager (в очередь контекста),
 * её подхватывает AddQueuedCookiesToResponse.
 *
 * Сохранение происходит и при ошибках: HTTP-конвейер превращает исключение в ответ внутри
 * цепочки, поэтому flash-сообщения и ошибки валидации не теряются.
 */
class StartSession
{
    public function __construct(protected readonly SessionManager $session) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->session->start();
        $request->setSession($this->session);

        $response = $next($request);

        $this->storePreviousUrl($request);
        $this->session->save();

        return $response;
    }

    /** Запоминает последнюю открытую страницу для back() (только обычные GET-страницы). */
    protected function storePreviousUrl(Request $request): void
    {
        if ($request->isMethod('GET') && $request->route() !== null && !$request->ajax() && !$request->expectsJson()) {
            $this->session->put('_previous.url', $request->fullUrl());
        }
    }
}
