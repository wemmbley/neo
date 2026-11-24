<?php

declare(strict_types=1);

namespace Neo\Network\Http\Testing;

use Neo\Network\Cookie\CookieValue;
use Neo\Network\Http\Contracts\ResponseContract;

final class ArrayResponse implements ResponseContract
{
    private array $cookies = [];

    public function withCookie(CookieValue $cookie): static
    {
        $this->cookies[$cookie->name] = $cookie;

        return $this;
    }

    public function cookies(): array
    {
        return $this->cookies;
    }

    public function cookie(string $name): ?CookieValue
    {
        return $this->cookies[$name] ?? null;
    }
}
