<?php

declare(strict_types=1);

namespace Neo\Network\Http\Exceptions;

use Throwable;

class MethodNotAllowedHttpException extends HttpException
{
    /** @param list<string> $allow */
    public function __construct(array $allow, string $message = '', ?Throwable $previous = null, array $headers = [])
    {
        $headers['Allow'] = strtoupper(implode(', ', $allow));

        parent::__construct(405, $message, $headers, 0, $previous);
    }
}
