<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI\Exceptions;

/** В теле записи поле вне `$fillable` или без правила в `$rules`. */
class PayloadFieldNotAllowedResourceAPIException extends ResourceAPIException
{
    protected int $status = 422;

    protected string $errorCode = 'payload_field_not_allowed';

    protected string $defaultMessage = 'The field is not allowed in the request body.';
}
