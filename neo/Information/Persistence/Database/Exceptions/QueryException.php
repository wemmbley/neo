<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Exceptions;

use Throwable;

class QueryException extends DatabaseException
{
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings,
        Throwable $previous,
    ) {
        parent::__construct(
            $previous->getMessage() . ' (SQL: ' . $sql . ')',
            (int) $previous->getCode(),
            $previous,
        );
    }
}
