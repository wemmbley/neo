<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI\Exceptions;

/** Gate или Policy отказали в действии (бросает адаптер Auth). */
class ForbiddenResourceAPIException extends ResourceAPIException
{
    protected int $status = 403;

    protected string $errorCode = 'forbidden';

    protected string $defaultMessage = 'This action is unauthorized.';
}
