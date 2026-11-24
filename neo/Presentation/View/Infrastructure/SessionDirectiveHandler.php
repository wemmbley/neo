<?php

namespace Neo\Presentation\View\Infrastructure;

use Neo\Network\Session\Session;
use Neo\Presentation\View\Directives\Contracts\SessionDirectiveContract;

class SessionDirectiveHandler implements SessionDirectiveContract
{
    public function get(string $key, mixed $default = null): mixed
    {
        return Session::get($key, $default);
    }

    public function has(string $key): bool
    {
        return Session::has($key);
    }

    public function put(string $key, mixed $value): void
    {
        Session::put($key, $value);
    }

    public function forget(string $key): void
    {
        Session::forget($key);
    }

    public function flush(): void
    {
        Session::flush();
    }
}