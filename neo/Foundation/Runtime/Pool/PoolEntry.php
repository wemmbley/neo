<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Pool;

/**
 * Слот пула. Пустой слот (resource = null) это «право создать новый ресурс»: так пул никогда не
 * превышает max, а ожидающие корутины просыпаются и когда ресурс вернули, и когда он был уничтожен.
 *
 * @internal
 */
final class PoolEntry
{
    public ?object $resource = null;

    public float $createdAt = 0.0;

    public float $lastUsedAt = 0.0;

    public function fill(object $resource): void
    {
        $this->resource = $resource;
        $this->createdAt = $this->lastUsedAt = microtime(true);
    }

    public function clear(): void
    {
        $this->resource = null;
    }
}
