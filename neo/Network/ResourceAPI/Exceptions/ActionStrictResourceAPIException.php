<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI\Exceptions;

/** Попытка вызвать запрещённое действие для ресурса. */
class ActionStrictResourceAPIException extends ResourceAPIException
{
    protected int $status = 405;

    protected string $errorCode = 'action_not_allowed';

    protected string $defaultMessage = 'The action is not allowed for this resource.';
}
