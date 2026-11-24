<?php

declare(strict_types=1);

namespace Neo\Network\Http\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Исключение с HTTP-статусом. Бросается из контроллера, middleware или через abort().
 * Единый Handler превращает его в ответ (JSON или HTML по заголовку Accept).
 */
class HttpException extends RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        private readonly array $headers = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
