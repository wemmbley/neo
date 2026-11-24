<?php

declare(strict_types=1);

namespace Neo\Network\Http\Testing;

use Neo\Network\Http\Request;

/** Запрос только с куками: для тестов Cookie и Session. */
final class ArrayRequest extends Request
{
    /** @param array<string, string> $cookies */
    public function __construct(array $cookies = [])
    {
        parent::__construct(cookies: $cookies);
    }
}
