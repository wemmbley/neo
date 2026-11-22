<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Query;

/**
 * Сырое SQL-выражение: вставляется в запрос как есть, без экранирования.
 */
final class Raw implements \Stringable
{
    public function __construct(public readonly string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }
}
