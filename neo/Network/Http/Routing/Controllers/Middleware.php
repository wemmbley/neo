<?php

declare(strict_types=1);

namespace Neo\Network\Http\Routing\Controllers;

use Closure;

/** Middleware контроллера с ограничением по методам. */
final class Middleware
{
    /** @var list<string>|null */
    private ?array $only = null;

    /** @var list<string>|null */
    private ?array $except = null;

    /**
     * @param string|Closure $middleware
     * @param list<string>|string|null $only
     * @param list<string>|string|null $except
     */
    public function __construct(
        public readonly string|Closure $middleware,
        array|string|null $only = null,
        array|string|null $except = null,
    ) {
        $this->only = $only === null ? null : (array) $only;
        $this->except = $except === null ? null : (array) $except;
    }

    /** @param list<string>|string $methods */
    public function only(array|string $methods): static
    {
        $this->only = (array) $methods;

        return $this;
    }

    /** @param list<string>|string $methods */
    public function except(array|string $methods): static
    {
        $this->except = (array) $methods;

        return $this;
    }

    public function appliesTo(string $method): bool
    {
        if ($this->only !== null && !in_array($method, $this->only, true)) {
            return false;
        }

        return $this->except === null || !in_array($method, $this->except, true);
    }
}
