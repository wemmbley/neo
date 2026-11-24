<?php

declare(strict_types=1);

namespace Neo\Network\Http\Exceptions;

use Throwable;

class NotFoundHttpException extends HttpException
{
    public function __construct(string $message = '', ?Throwable $previous = null, array $headers = [])
    {
        parent::__construct(404, $message, $headers, 0, $previous);
    }
}
