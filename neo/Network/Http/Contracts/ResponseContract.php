<?php

declare(strict_types=1);

namespace Neo\Network\Http\Contracts;

use Neo\Network\Cookie\CookieValue;

interface ResponseContract
{
    public function withCookie(CookieValue $cookie): void;
}
