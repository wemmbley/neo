<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI\Exceptions;

/** Пользователь ввёл некорректные данные в запрос. */
class IncorrectRequestParamsResourceAPIException extends ResourceAPIException
{
    protected int $status = 400;

    protected string $errorCode = 'incorrect_request_params';

    protected string $defaultMessage = 'The request parameters are incorrect.';
}
