<?php

declare(strict_types=1);

namespace Neo\Network\Http\Exceptions;

/** CSRF-токен не прошёл проверку (HTTP 419, как в Laravel). */
class TokenMismatchException extends HttpException
{
    public function __construct(string $message = 'CSRF token mismatch.')
    {
        parent::__construct(419, $message);
    }
}
