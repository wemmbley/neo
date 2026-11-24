<?php

declare(strict_types=1);

namespace Neo\Security\Authorization;

use Neo\Network\Http\Exceptions\HttpException;
use Throwable;

/**
 * 403 (или другой статус, который выбрала политика): действие запрещено.
 * Handler превращает её в JSON или HTML-страницу по заголовку Accept.
 */
class AuthorizationException extends HttpException
{
    public function __construct(string $message = '', int $status = 403, ?Throwable $previous = null)
    {
        parent::__construct($status, $message === '' ? 'This action is unauthorized.' : $message, [], 0, $previous);
    }
}
