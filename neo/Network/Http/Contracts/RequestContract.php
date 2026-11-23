<?php

declare(strict_types=1);

namespace Neo\Network\Http\Contracts;

interface RequestContract
{
    /** @return array<string, string> */
    public function cookies(): array;
}
