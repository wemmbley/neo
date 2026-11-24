<?php

declare(strict_types=1);

namespace Neo\Network\Http\Exceptions;

/** Слишком много запросов (HTTP 429). */
class ThrottleRequestsException extends HttpException
{
    public function __construct(string $message = 'Too Many Requests.', array $headers = [])
    {
        parent::__construct(429, $message, $headers);
    }
}
