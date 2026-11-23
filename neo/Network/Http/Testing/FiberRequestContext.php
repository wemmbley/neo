<?php

declare(strict_types=1);

namespace Neo\Network\Http\Testing;

use Fiber;
use Neo\Network\Http\Contracts\RequestContextContract;
use Neo\Network\Http\Contracts\RequestContract;
use Neo\Network\Http\Contracts\ResponseContract;
use LogicException;
use WeakMap;

#
# Временный HTTP-контекст для разработки и тестов.
# Для Swoole production-адаптер должен использовать coroutine-local context.
#
final class FiberRequestContext implements RequestContextContract
{
    /** @var WeakMap<Fiber, array{request: RequestContract, response: ResponseContract, data: array}> */
    private WeakMap $fibers;

    /** @var array{request: RequestContract, response: ResponseContract, data: array}|null */
    private ?array $main = null;

    public function __construct()
    {
        $this->fibers = new WeakMap();
    }

    public function enter(RequestContract $request, ResponseContract $response): void
    {
        $state = ['request' => $request, 'response' => $response, 'data' => []];
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            $this->main = $state;
            return;
        }

        $this->fibers[$fiber] = $state;
    }

    public function leave(): void
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            $this->main = null;
            return;
        }
        unset($this->fibers[$fiber]);
    }

    public function request(): RequestContract
    {
        return $this->state()['request'];
    }

    public function response(): ResponseContract
    {
        return $this->state()['response'];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->state()['data'][$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $state = $this->state();
        $state['data'][$key] = $value;
        $this->store($state);
    }

    public function forget(string $key): void
    {
        $state = $this->state();
        unset($state['data'][$key]);
        $this->store($state);
    }

    private function state(): array
    {
        $fiber = Fiber::getCurrent();
        $state = $fiber === null ? $this->main : ($this->fibers[$fiber] ?? null);
        if ($state === null) {
            throw new LogicException('HTTP request context has not been entered.');
        }
        return $state;
    }

    private function store(array $state): void
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            $this->main = $state;
            return;
        }
        $this->fibers[$fiber] = $state;
    }
}
