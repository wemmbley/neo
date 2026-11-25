<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI\Exceptions;

/** Попытка сортировки по `HasMany` или `BelongsToMany` (дубли строк). */
class SortByManyRelationResourceAPIException extends ResourceAPIException
{
    protected int $status = 422;

    protected string $errorCode = 'sort_not_allowed';

    protected string $defaultMessage = 'Sorting by a to-many relation is not allowed.';
}
