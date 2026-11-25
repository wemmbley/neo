<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI\Exceptions;

/** Запрашиваемая модель или ресурс не найдены на сервере. */
class ModelNotFoundResourceAPIException extends ResourceAPIException
{
    protected int $status = 404;

    protected string $errorCode = 'model_not_found';

    protected string $defaultMessage = 'The requested model was not found.';
}
