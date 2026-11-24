<?php

declare(strict_types=1);

namespace Neo\Network\Http\Contracts;

use Neo\Network\Cookie\CookieValue;

interface ResponseContract
{
    /** Ставит куку в очередь на отправку (одна кука на name+path+domain). */
    public function withCookie(CookieValue $cookie): static;

    /** @return array<string, CookieValue> */
    public function cookies(): array;
}
