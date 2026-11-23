<?php

declare(strict_types=1);

namespace Neo\Network\Http\Testing;

use Neo\Network\Http\Contracts\RequestContract;

final class ArrayRequest implements RequestContract
{
    public function __construct(private readonly array $cookies = []) {}

    public function cookies(): array
    {
        return $this->cookies;
    }
}
