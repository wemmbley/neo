<?php

declare(strict_types=1);

namespace Neo\Testing;

/**
 * Проверки в стиле Pest: `expect($value)->toBe(1)->not->toBeNull()`.
 *
 * Каждая проверка возвращает сам объект, поэтому их можно соединять в цепочку.
 * `->not` меняет смысл следующей проверки на противоположный.
 *
 * @property-read Expectation $not
 */
final class Expectation
{
    public function __construct(
        public readonly mixed $value,
        private readonly bool $negated = false,
    ) {
    }

    /**
     * Модификатор `->not`.
     */
    public function __get(string $name): self
    {
        if ($name === 'not') {
            return new self($this->value, !$this->negated);
        }

        throw new \LogicException("У expect() нет свойства «{$name}».");
    }

    /**
     * Начинает новую проверку для другого значения, не прерывая цепочку.
     */
    public function and(mixed $value): self
    {
        return new self($value);
    }

    // ------------------------------------------------------------------
    // Сравнения
    // ------------------------------------------------------------------

    /** Строгое равенство (===). */
    public function toBe(mixed $expected): self
    {
        return $this->check(
            $this->value === $expected,
            'Failed asserting that two values are identical.',
            'Failed asserting that the value is not identical to ' . self::export($expected) . '.',
            $expected,
            true
        );
    }

    /** Нестрогое равенство (==). */
    public function toEqual(mixed $expected): self
    {
        return $this->check(
            $this->value == $expected,
            'Failed asserting that two values are equal.',
            'Failed asserting that the value is not equal to ' . self::export($expected) . '.',
            $expected,
            true
        );
    }

    public function toBeGreaterThan(int|float $limit): self
    {
        return $this->check($this->value > $limit, 'Expected ' . self::export($this->value) . " to be greater than {$limit}.", 'Expected ' . self::export($this->value) . " not to be greater than {$limit}.");
    }

    public function toBeLessThan(int|float $limit): self
    {
        return $this->check($this->value < $limit, 'Expected ' . self::export($this->value) . " to be less than {$limit}.", 'Expected ' . self::export($this->value) . " not to be less than {$limit}.");
    }

    // ------------------------------------------------------------------
    // Типы и значения
    // ------------------------------------------------------------------

    public function toBeTrue(): self
    {
        return $this->check($this->value === true, 'Expected ' . self::export($this->value) . ' to be true.', 'Expected the value not to be true.');
    }

    public function toBeFalse(): self
    {
        return $this->check($this->value === false, 'Expected ' . self::export($this->value) . ' to be false.', 'Expected the value not to be false.');
    }

    public function toBeNull(): self
    {
        return $this->check($this->value === null, 'Expected ' . self::export($this->value) . ' to be null.', 'Expected the value not to be null.');
    }

    public function toBeEmpty(): self
    {
        return $this->check(empty($this->value), 'Expected ' . self::export($this->value) . ' to be empty.', 'Expected the value not to be empty.');
    }

    public function toBeString(): self
    {
        return $this->check(is_string($this->value), 'Expected ' . self::export($this->value) . ' to be a string.', 'Expected the value not to be a string.');
    }

    public function toBeInt(): self
    {
        return $this->check(is_int($this->value), 'Expected ' . self::export($this->value) . ' to be an int.', 'Expected the value not to be an int.');
    }

    public function toBeArray(): self
    {
        return $this->check(is_array($this->value), 'Expected ' . self::export($this->value) . ' to be an array.', 'Expected the value not to be an array.');
    }

    public function toBeInstanceOf(string $class): self
    {
        return $this->check(
            $this->value instanceof $class,
            'Expected ' . self::export($this->value) . " to be an instance of {$class}.",
            "Expected the value not to be an instance of {$class}."
        );
    }

    public function toBeFile(): self
    {
        return $this->check(is_string($this->value) && is_file($this->value), 'Expected ' . self::export($this->value) . ' to be an existing file.', 'Expected ' . self::export($this->value) . ' not to be an existing file.');
    }

    // ------------------------------------------------------------------
    // Содержимое
    // ------------------------------------------------------------------

    /**
     * Для строки: содержит подстроки. Для массива: содержит значения (строго).
     */
    public function toContain(mixed ...$needles): self
    {
        foreach ($needles as $needle) {
            $found = match (true) {
                is_string($this->value) => is_string($needle) && str_contains($this->value, $needle),
                is_array($this->value) => in_array($needle, $this->value, true),
                default => throw new \InvalidArgumentException('toContain() работает со строкой или массивом.'),
            };

            $this->check($found, 'Expected ' . self::export($this->value) . ' to contain ' . self::export($needle) . '.', 'Expected ' . self::export($this->value) . ' not to contain ' . self::export($needle) . '.');
        }

        return $this->afterCheck();
    }

    public function toHaveCount(int $count): self
    {
        $actual = is_countable($this->value) ? count($this->value) : -1;

        return $this->check($actual === $count, "Expected count {$count}, got {$actual}.", "Expected the count not to be {$count}.");
    }

    public function toHaveKey(int|string $key): self
    {
        return $this->check(is_array($this->value) && array_key_exists($key, $this->value), 'Expected the array to have key ' . self::export($key) . '.', 'Expected the array not to have key ' . self::export($key) . '.');
    }

    public function toStartWith(string $prefix): self
    {
        return $this->check(is_string($this->value) && str_starts_with($this->value, $prefix), 'Expected ' . self::export($this->value) . ' to start with ' . self::export($prefix) . '.', 'Expected the string not to start with ' . self::export($prefix) . '.');
    }

    public function toEndWith(string $suffix): self
    {
        return $this->check(is_string($this->value) && str_ends_with($this->value, $suffix), 'Expected ' . self::export($this->value) . ' to end with ' . self::export($suffix) . '.', 'Expected the string not to end with ' . self::export($suffix) . '.');
    }

    /** Строка подходит под регулярное выражение. */
    public function toMatch(string $pattern): self
    {
        return $this->check(is_string($this->value) && preg_match($pattern, $this->value) === 1, 'Expected ' . self::export($this->value) . " to match {$pattern}.", "Expected the string not to match {$pattern}.");
    }

    // ------------------------------------------------------------------
    // Исключения
    // ------------------------------------------------------------------

    /**
     * Значение должно быть замыканием, которое бросает исключение.
     *
     * @param class-string<\Throwable> $class   Ожидаемый класс (по умолчанию любой).
     * @param string|null              $message Подстрока сообщения.
     */
    public function toThrow(string $class = \Throwable::class, ?string $message = null): self
    {
        if (!$this->value instanceof \Closure) {
            throw new \InvalidArgumentException('toThrow() ожидает замыкание: expect(fn () => ...)->toThrow().');
        }

        $thrown = null;

        try {
            ($this->value)();
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $matches = $thrown !== null
            && $thrown instanceof $class
            && ($message === null || str_contains($thrown->getMessage(), $message));

        $wanted = $class . ($message !== null ? ' with message containing ' . self::export($message) : '');
        $got = $thrown === null ? 'nothing was thrown' : $thrown::class . ': ' . self::export($thrown->getMessage());

        return $this->check($matches, "Expected {$wanted}, but {$got}.", "Expected {$wanted} not to be thrown, but it was: {$got}.");
    }

    // ------------------------------------------------------------------
    // Внутреннее
    // ------------------------------------------------------------------

    /**
     * Короткое текстовое представление значения для сообщений об ошибках.
     */
    public static function export(mixed $value, int $depth = 0): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => var_export($value, true),
            is_string($value) => self::exportString($value),
            is_array($value) => self::exportArray($value, $depth),
            $value instanceof \Closure => 'Closure',
            is_object($value) => $value::class . ' {…}',
            default => get_debug_type($value),
        };
    }

    private static function exportString(string $value): string
    {
        $json = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        // Режем по символам, а не по байтам, чтобы не порвать UTF-8 (и без mbstring).
        return preg_match('/^.{301}/su', $json) === 1 ? preg_replace('/^(.{300}).*$/su', '$1…"', $json) : $json;
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private static function exportArray(array $value, int $depth): string
    {
        if ($depth >= 2) {
            return '[…]';
        }

        $isList = array_is_list($value);
        $parts = [];

        foreach (array_slice($value, 0, 8, true) as $key => $item) {
            $parts[] = ($isList ? '' : self::export($key) . ' => ') . self::export($item, $depth + 1);
        }

        return '[' . implode(', ', $parts) . (count($value) > 8 ? ', …' : '') . ']';
    }

    /**
     * Что вернуть после успешной проверки: `->not` действует только на одну проверку,
     * поэтому дальше цепочка продолжается уже без отрицания (как в Pest).
     */
    private function afterCheck(): self
    {
        return $this->negated ? new self($this->value) : $this;
    }

    /**
     * Общая точка проверки: учитывает `->not`, считает проверки, бросает AssertionFailed.
     *
     * @param bool $diff Показать «ожидалось/получено» (для toBe и toEqual).
     */
    private function check(bool $passes, string $positive, string $negative, mixed $expected = null, bool $diff = false): self
    {
        Runner::countAssertion();

        if ($passes !== $this->negated) {
            return $this->afterCheck();
        }

        $showDiff = $diff && !$this->negated;

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (isset($frame['file']) && !str_starts_with($frame['file'], __DIR__)) {
                $file = $frame['file'];
                $line = $frame['line'] ?? 0;
                break;
            }
        }

        throw new AssertionFailed(
            $this->negated ? $negative : $positive,
            $showDiff ? self::export($expected) : null,
            $showDiff ? self::export($this->value) : null,
            $file ?? '',
            $line ?? 0,
        );
    }
}
