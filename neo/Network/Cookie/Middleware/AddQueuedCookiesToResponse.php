<?php

declare(strict_types=1);

namespace Neo\Network\Cookie\Middleware;

use Closure;
use Neo\Network\Http\Contracts\RequestContextContract;
use Neo\Network\Http\Request;
use Neo\Network\Http\Response;

/**
 * Переносит куки, поставленные через Cookie::set()/queue() и Session, из «ожидающего»
 * ответа контекста в настоящий ответ. Стоит ВНУТРИ EncryptCookies, чтобы эти куки тоже
 * были зашифрованы.
 */
class AddQueuedCookiesToResponse
{
    public function __construct(protected readonly RequestContextContract $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $pending = $this->context->response();

        if ($pending instanceof Response && $pending !== $response) {
            foreach ($pending->cookies() as $cookie) {
                $response->withCookie($cookie);
            }

            $pending->setCookies([]);
        }

        return $response;
    }
}
