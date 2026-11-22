<?php

use Neo\Information\Collection\Collection;

if (! function_exists('collect')) {
    /**
     * Create a collection from the given value (Laravel-compatible helper).
     */
    function collect(mixed $value = []): Collection
    {
        return new Collection($value);
    }
}
