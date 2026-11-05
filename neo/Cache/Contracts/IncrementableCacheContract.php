<?php

declare(strict_types=1);

namespace Neo\Cache\Contracts;

interface IncrementableCacheContract
{
    /**
     * Увеличить числовое значение.
     */
    public function increment(string $key, int|float $value = 1): int|float|false;

    /**
     * Уменьшить числовое значение.
     */
    public function decrement(string $key, int|float $value = 1): int|float|false;
}