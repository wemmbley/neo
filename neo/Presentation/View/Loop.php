<?php

declare(strict_types=1);

namespace Neo\Presentation\View;

/**
 * Переменная $loop внутри @foreach.
 *
 * Объект создаётся рендерером на каждый @foreach и «тикает» на каждой итерации.
 */
class Loop
{
    # Индекс текущей итерации (с 0).
    public int $index = 0;

    # Номер текущей итерации (с 1).
    public int $iteration = 1;

    # Сколько итераций осталось.
    public int $remaining = 0;

    # Сколько всего элементов (0, если итератор не Countable).
    public int $count = 0;

    # Первая итерация?
    public bool $first = true;

    # Последняя итерация? (для не-Countable всегда false)
    public bool $last = false;

    # Чётная итерация (по номеру, как в Blade)?
    public bool $even = false;

    # Нечётная итерация?
    public bool $odd = true;

    # Уровень вложенности (1 — внешний цикл).
    public int $depth = 1;

    # Родительский $loop при вложенных циклах.
    public ?Loop $parent = null;

    private bool $started = false;

    public function __construct(iterable $items, int $depth = 1, ?Loop $parent = null)
    {
        $this->count = is_countable($items) ? count($items) : 0;
        $this->depth = $depth;
        $this->parent = $parent;
        $this->refresh();
    }

    /**
     * Перейти к следующей итерации. Первый вызов остаётся на нулевой.
     */
    public function advance(): static
    {
        if ($this->started) {
            $this->index++;
            $this->iteration++;
        }

        $this->started = true;
        $this->refresh();

        return $this;
    }

    private function refresh(): void
    {
        $this->remaining = $this->count > 0 ? $this->count - $this->iteration : 0;
        $this->first = $this->index === 0;
        $this->last = $this->count > 0 && $this->iteration === $this->count;
        $this->even = $this->iteration % 2 === 0;
        $this->odd = !$this->even;
    }
}
