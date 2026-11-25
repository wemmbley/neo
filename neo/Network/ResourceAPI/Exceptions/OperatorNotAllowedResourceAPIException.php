<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI\Exceptions;

/** Оператор неизвестен или не входит в `$filterOperators`. */
class OperatorNotAllowedResourceAPIException extends ResourceAPIException
{
    protected int $status = 422;

    protected string $errorCode = 'operator_not_allowed';

    protected string $defaultMessage = 'The filter operator is not allowed.';
}
