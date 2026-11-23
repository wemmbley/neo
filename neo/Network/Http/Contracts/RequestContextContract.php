<?php

declare(strict_types=1);

namespace Neo\Network\Http\Contracts;

interface RequestContextContract
{
    public function enter(RequestContract $request, ResponseContract $response): void;

    public function leave(): void;

    public function request(): RequestContract;

    public function response(): ResponseContract;

    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function forget(string $key): void;
}
