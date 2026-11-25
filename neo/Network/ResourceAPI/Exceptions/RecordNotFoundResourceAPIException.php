<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI\Exceptions;

/** Запись с указанным id не найдена. */
class RecordNotFoundResourceAPIException extends ResourceAPIException
{
    protected int $status = 404;

    protected string $errorCode = 'record_not_found';

    protected string $defaultMessage = 'The requested record was not found.';
}
