<?php

declare(strict_types=1);

namespace Neo\Network\Http\Exceptions;

/** Подозрительный запрос: битый Host, нулевой байт в пути и т. п. (HTTP 400). */
class SuspiciousOperationException extends HttpException
{
    public function __construct(string $message = 'Bad Request.')
    {
        parent::__construct(400, $message);
    }
}
