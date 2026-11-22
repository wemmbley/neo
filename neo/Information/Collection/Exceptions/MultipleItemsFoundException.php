<?php

namespace Neo\Information\Collection\Exceptions;

use RuntimeException;

class MultipleItemsFoundException extends RuntimeException
{
    public function __construct(public readonly int $count, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct("{$count} items were found.", $code, $previous);
    }

    public function getCount(): int
    {
        return $this->count;
    }
}
