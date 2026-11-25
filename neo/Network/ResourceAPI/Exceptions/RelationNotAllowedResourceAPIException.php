<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI\Exceptions;

/** Связь не имеет своего ресурса, не разрешена или запрещена Policy. */
class RelationNotAllowedResourceAPIException extends ResourceAPIException
{
    protected int $status = 422;

    protected string $errorCode = 'relation_not_allowed';

    protected string $defaultMessage = 'The relation is not available.';
}
