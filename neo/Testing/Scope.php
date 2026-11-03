<?php

declare(strict_types=1);

namespace Neo\Testing;

/**
 * Область теста: файл целиком или группа `describe()`.
 *
 * Хуки `beforeEach` / `afterEach` принадлежат области и действуют на все её тесты,
 * включая тесты вложенных групп.
 */
final class Scope
{
    /** @var list<\Closure> */
    public array $before = [];

    /** @var list<\Closure> */
    public array $after = [];

    public function __construct(
        public readonly string $name = '',
        public readonly ?Scope $parent = null,
    ) {
    }

    /**
     * Цепочка областей от корня к текущей.
     *
     * @return list<Scope>
     */
    public function chain(): array
    {
        return $this->parent === null ? [$this] : [...$this->parent->chain(), $this];
    }

    /**
     * Полное имя теста с названиями групп: «группа › вложенная › тест».
     */
    public function prefix(string $description): string
    {
        $names = array_filter(
            array_map(static fn (Scope $s): string => $s->name, $this->chain()),
            static fn (string $n): bool => $n !== ''
        );

        return implode(' › ', [...$names, $description]);
    }
}
