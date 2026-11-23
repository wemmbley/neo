<?php

namespace Neo\Presentation\View\Directives\Contracts;

interface SessionDirectiveContract
{
    public function get(string $key, mixed $default = null): mixed;

    public function has(string $key): bool;

    public function put(string $key, mixed $value): void;

    public function forget(string $key): void;

    public function flush(): void;
}