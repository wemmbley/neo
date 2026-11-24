<?php

declare(strict_types=1);

namespace Neo\Network\Http\Routing;

use Closure;
use InvalidArgumentException;

/**
 * Реестр middleware: псевдонимы, группы, глобальный стек и приоритет выполнения.
 *
 *   Middleware::register(['auth' => Authenticate::class, 'throttle' => ThrottleRequests::class]);
 *   Middleware::group('web', [EncryptCookies::class, StartSession::class]);
 *   Route::middleware(['web', 'throttle:60,1'])->group(...);
 *
 * Заполняется при загрузке приложения и дальше только читается, поэтому один экземпляр
 * на процесс безопасен для Swoole.
 *
 * Незарегистрированное имя НЕ игнорируется, а даёт исключение: молча пропущенный `auth`
 * оставил бы маршрут открытым.
 */
final class MiddlewareRegistry
{
    /** @var array<string, class-string> */
    private array $aliases = [];

    /** @var array<string, list<string>> */
    private array $groups = [];

    /** @var list<string> */
    private array $global = [];

    /** @var list<string> */
    private array $priority = [];

    /**
     * Регистрирует псевдонимы: `register(['auth' => Class::class])` или `register('auth', Class::class)`.
     *
     * @param array<string, class-string>|string $aliases
     */
    public function register(array|string $aliases, ?string $class = null): static
    {
        if (is_string($aliases)) {
            $aliases = [$aliases => (string) $class];
        }

        foreach ($aliases as $alias => $target) {
            $this->aliases[$alias] = $target;
        }

        return $this;
    }

    public function alias(string $alias, string $class): static
    {
        return $this->register($alias, $class);
    }

    /** @param list<string> $middleware */
    public function group(string $name, array $middleware): static
    {
        $this->groups[$name] = array_values($middleware);

        return $this;
    }

    /** @param list<string>|string $middleware */
    public function appendToGroup(string $group, array|string $middleware): static
    {
        $this->groups[$group] = array_values(array_unique([...($this->groups[$group] ?? []), ...(array) $middleware]));

        return $this;
    }

    /** @param list<string>|string $middleware */
    public function prependToGroup(string $group, array|string $middleware): static
    {
        $this->groups[$group] = array_values(array_unique([...(array) $middleware, ...($this->groups[$group] ?? [])]));

        return $this;
    }

    /** @param list<string> $middleware глобальные (на каждый запрос, ДО маршрутизации) */
    public function global(array $middleware): static
    {
        $this->global = array_values($middleware);

        return $this;
    }

    /** @param list<string>|string $middleware */
    public function appendGlobal(array|string $middleware): static
    {
        $this->global = array_values(array_unique([...$this->global, ...(array) $middleware]));

        return $this;
    }

    /** @param list<string>|string $middleware */
    public function prependGlobal(array|string $middleware): static
    {
        $this->global = array_values(array_unique([...(array) $middleware, ...$this->global]));

        return $this;
    }

    /**
     * Относительный порядок маршрутных middleware: перечисленные выполняются в этой
     * последовательности независимо от порядка, в котором их указали.
     *
     * @param list<class-string> $priority
     */
    public function priority(array $priority): static
    {
        $this->priority = array_values($priority);

        return $this;
    }

    public function hasAlias(string $alias): bool
    {
        return isset($this->aliases[$alias]);
    }

    /** @return array<string, class-string> */
    public function aliases(): array
    {
        return $this->aliases;
    }

    /** @return array<string, list<string>> */
    public function groups(): array
    {
        return $this->groups;
    }

    /** @return list<mixed> */
    public function getGlobal(): array
    {
        return $this->resolve($this->global, [], false);
    }

    /**
     * Разворачивает псевдонимы и группы в список «Класс[:параметры]», Closure и объектов.
     *
     * @param array<mixed> $names
     * @param array<string> $excluded имена, которые нужно убрать (withoutMiddleware)
     * @return list<mixed>
     */
    public function resolve(array $names, array $excluded = [], bool $sort = true): array
    {
        $resolved = [];

        foreach ($names as $name) {
            if ($name instanceof Closure || is_object($name)) {
                $resolved[] = $name;
                continue;
            }

            array_push($resolved, ...$this->expand((string) $name));
        }

        if ($excluded !== []) {
            $skip = [];

            foreach ($excluded as $name) {
                foreach ($this->expand($name) as $item) {
                    $skip[$this->classOf($item)] = true;
                }
            }

            $resolved = array_values(array_filter(
                $resolved,
                fn ($item): bool => !is_string($item) || !isset($skip[$this->classOf($item)]),
            ));
        }

        # Дубликаты строк убираем, сохраняя порядок первого вхождения.
        $seen = [];
        $unique = [];
        foreach ($resolved as $item) {
            if (is_string($item)) {
                if (isset($seen[$item])) {
                    continue;
                }
                $seen[$item] = true;
            }
            $unique[] = $item;
        }

        return $sort ? $this->sort($unique) : $unique;
    }

    /**
     * @return list<string>
     */
    private function expand(string $name): array
    {
        [$base, $parameters] = array_pad(explode(':', $name, 2), 2, null);
        $suffix = $parameters === null ? '' : ':' . $parameters;

        if (isset($this->groups[$base]) && $parameters === null) {
            $result = [];

            foreach ($this->groups[$base] as $item) {
                array_push($result, ...$this->expand($item));
            }

            return $result;
        }

        if (isset($this->aliases[$base])) {
            return [$this->aliases[$base] . $suffix];
        }

        if (class_exists($base)) {
            return [$base . $suffix];
        }

        throw new InvalidArgumentException(
            "Middleware [{$name}] не зарегистрирован. Добавьте его через Middleware::register() или Middleware::group()."
        );
    }

    private function classOf(string $item): string
    {
        return explode(':', $item, 2)[0];
    }

    /**
     * Переставляет приоритетные middleware между собой на их же местах;
     * остальные остаются там, где стояли.
     *
     * @param list<mixed> $middleware
     * @return list<mixed>
     */
    private function sort(array $middleware): array
    {
        if ($this->priority === [] || count($middleware) < 2) {
            return $middleware;
        }

        $order = array_flip($this->priority);
        $slots = [];
        $items = [];

        foreach ($middleware as $index => $item) {
            if (is_string($item) && isset($order[$this->classOf($item)])) {
                $slots[] = $index;
                $items[] = $item;
            }
        }

        usort($items, fn (string $a, string $b): int => $order[$this->classOf($a)] <=> $order[$this->classOf($b)]);

        foreach ($slots as $i => $slot) {
            $middleware[$slot] = $items[$i];
        }

        return $middleware;
    }
}
