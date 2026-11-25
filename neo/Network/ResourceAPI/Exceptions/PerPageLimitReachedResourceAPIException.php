<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI\Exceptions;

/** Превышен пользовательский лимит записей на страницу. */
class PerPageLimitReachedResourceAPIException extends ResourceAPIException
{
    protected int $status = 422;

    protected string $errorCode = 'per_page_limit_reached';

    protected string $defaultMessage = 'The page size limit is exceeded.';
}
