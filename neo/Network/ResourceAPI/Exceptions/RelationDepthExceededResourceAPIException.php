<?php

declare(strict_types=1);

namespace Neo\Network\ResourceAPI\Exceptions;

/** Превышена глубина вложений `relation_deep`. */
class RelationDepthExceededResourceAPIException extends ResourceAPIException
{
    protected int $status = 422;

    protected string $errorCode = 'relation_depth_exceeded';

    protected string $defaultMessage = 'The relation depth limit is exceeded.';
}
