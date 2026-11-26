<?php

declare(strict_types=1);

namespace Neo\Information\Markdown\Engine;

/**
 * Курсор по строкам документа. Передаётся в BlockSyntaxContract::matchBlock().
 */
final class Lines
{
    /**
     * Выставляется парсером: перед текущей строкой накоплен незакрытый абзац.
     */
    public bool $paragraphOpen = false;

    private int $index = 0;

    /**
     * @param list<string> $lines
     */
    public function __construct(
        private readonly array $lines,
        private readonly int $offset = 0,
    ) {}

    public function eof(): bool
    {
        return $this->index >= count($this->lines);
    }

    public function current(): string
    {
        return $this->lines[$this->index];
    }

    /**
     * Строка через $ahead позиций от текущей или null.
     */
    public function peek(int $ahead = 0): ?string
    {
        return $this->lines[$this->index + $ahead] ?? null;
    }

    /**
     * Номер строки в исходном документе (с 1).
     */
    public function number(int $ahead = 0): int
    {
        return $this->offset + $this->index + $ahead + 1;
    }

    public function advance(int $count): void
    {
        $this->index += $count;
    }

    /**
     * Текущая строка не продолжает абзац (до неё пустая строка, начало или другой блок).
     */
    public function standalone(): bool
    {
        return !$this->paragraphOpen;
    }

    public function nextBlank(): bool
    {
        $next = $this->peek(1);

        return $next === null || trim($next) === '';
    }
}
