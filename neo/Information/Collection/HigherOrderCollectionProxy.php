<?php

namespace Neo\Information\Collection;

/**
 * Higher-order proxy: $collection->map->name, $collection->each->save(), ...
 */
class HigherOrderCollectionProxy
{
    public function __construct(protected Collection $collection, protected string $method)
    {
    }

    public function __get(string $key): mixed
    {
        return $this->collection->{$this->method}(
            fn ($value) => is_array($value) ? ($value[$key] ?? null) : $value->{$key}
        );
    }

    public function __call(string $method, array $parameters): mixed
    {
        return $this->collection->{$this->method}(
            fn ($value) => $value->{$method}(...$parameters)
        );
    }
}
