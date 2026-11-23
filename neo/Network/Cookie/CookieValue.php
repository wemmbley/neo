<?php

declare(strict_types=1);

namespace Neo\Network\Cookie;

final readonly class CookieValue
{
    public function __construct(
        public string $name,
        public string $value,
        public ?int $expires = null,
        public string $path = '/',
        public ?string $domain = null,
        public bool $secure = false,
        public bool $httpOnly = true,
        public ?string $sameSite = 'lax',
        public bool $partitioned = false,
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('Cookie name must not be empty.');
        }
        if ($sameSite !== null && !in_array(strtolower($sameSite), ['lax', 'strict', 'none'], true)) {
            throw new \InvalidArgumentException('Cookie same_site must be lax, strict, none or null.');
        }
        if ($partitioned && (!$secure || strtolower((string) $sameSite) !== 'none')) {
            throw new \InvalidArgumentException('Partitioned cookies require secure=true and same_site=none.');
        }
    }
}
