<?php

declare(strict_types=1);

namespace Neo\Foundation\Runtime\Sync;

use Fiber;
use Neo\Foundation\Runtime\ExecutionContext;
use WeakMap;

/**
 * Контекст без корутин: хранилище процесса (один запрос на процесс, классический SAPI),
 * а внутри Fiber (тесты, параллельные задачи) отдельное хранилище на каждый Fiber.
 * Родителя нет: Fiber не наследует состояние процесса неявно.
 */
final class ProcessContext implements ExecutionContext
{
    /** @var array<string, mixed> */
    private array $main = [];

    /** @var WeakMap<Fiber, array<string, mixed>> */
    private WeakMap $fibers;

    public function __construct()
    {
        $this->fibers = new WeakMap();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->read()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            $this->main[$key] = $value;

            return;
        }

        $data = $this->fibers[$fiber] ?? [];
        $data[$key] = $value;
        $this->fibers[$fiber] = $data;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->read());
    }

    public function forget(string $key): void
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            unset($this->main[$key]);

            return;
        }

        if (isset($this->fibers[$fiber])) {
            $data = $this->fibers[$fiber];
            unset($data[$key]);
            $this->fibers[$fiber] = $data;
        }
    }

    public function parent(): ?ExecutionContext
    {
        return null;
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $fiber = Fiber::getCurrent();

        return $fiber === null ? $this->main : ($this->fibers[$fiber] ?? []);
    }
}
