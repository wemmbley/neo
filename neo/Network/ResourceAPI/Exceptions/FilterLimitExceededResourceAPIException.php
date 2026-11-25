<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI\Exceptions;

/** Превышен `max_conditions`, `max_in_items` или `max_value_length`. */
class FilterLimitExceededResourceAPIException extends ResourceAPIException
{
    protected int $status = 422;

    protected string $errorCode = 'filter_limit_exceeded';

    protected string $defaultMessage = 'The filter limit is exceeded.';
}
