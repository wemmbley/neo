<?php

declare(strict_types=1);

namespace Neo\Diagnostic\Benchmark\Support;

use Closure;

/** Считает SQL-запросы и время в БД через Connection::listen(). */
final class Probe
{
    public int $queries = 0;

    public float $ms = 0.0;

    private bool $active = true;

    /** @return Closure(string, array, float): void */
    public function listener(): Closure
    {
        return function (string $sql, array $bindings, float $ms): void {
            if ($this->active) {
                $this->queries++;
                $this->ms += $ms;
            }
        };
    }

    public function reset(): void
    {
        $this->queries = 0;
        $this->ms = 0.0;
        $this->active = true;
    }

    public function stop(): void
    {
        $this->active = false;
    }
}
