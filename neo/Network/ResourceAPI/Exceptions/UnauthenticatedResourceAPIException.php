<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI\Exceptions;

/** Пользователь не опознан (бросает адаптер Auth). */
class UnauthenticatedResourceAPIException extends ResourceAPIException
{
    protected int $status = 401;

    protected string $errorCode = 'unauthenticated';

    protected string $defaultMessage = 'Unauthenticated.';
}
