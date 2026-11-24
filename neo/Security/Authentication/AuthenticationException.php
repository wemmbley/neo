<?php

declare(strict_types=1);

namespace Neo\Security\Authentication;

use Neo\Network\Http\Exceptions\HttpException;
use Throwable;

/**
 * 401: пользователь не вошёл. Для браузера Handler (см. AuthServiceProvider)
 * превращает её в редирект на `auth.redirect_to`, для JSON-клиентов остаётся 401.
 */
class AuthenticationException extends HttpException
{
    /** @param list<string|null> $guards */
    public function __construct(
        string $message = 'Unauthenticated.',
        public readonly array $guards = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct(401, $message, [], 0, $previous);
    }
}
