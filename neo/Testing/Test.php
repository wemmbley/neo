<?php

declare(strict_types=1);

namespace Neo\Testing;

/**
 * Описание одного теста. Возвращается из `test()` и `it()`,
 * чтобы можно было дописать `->with([...])` или `->skip()`.
 */
final class Test
{
    /** @var array<int|string, mixed> */
    private array $datasets = [];

    private ?string $skipReason = null;

    public function __construct(
        public readonly string $description,
        public readonly ?\Closure $closure,
        public readonly Scope $scope,
    ) {
    }

    /**
     * Запускает тест по разу на каждую строку набора данных.
     *
     * Строка может быть массивом (аргументы по порядку) или одним значением.
     * Строковый ключ попадает в название теста.
     *
     * @param array<int|string, mixed> $data
     */
    public function with(array $data): static
    {
        $this->datasets = $data;

        return $this;
    }

    /**
     * Пропускает тест.
     *
     *   ->skip()                              // всегда
     *   ->skip('причина')                     // всегда, с причиной (как раньше)
     *   ->skip(PHP_OS_FAMILY === 'Windows', 'нет chmod')   // по условию
     *   ->skip(fn () => !extension_loaded('gd'), 'нужен GD') // условие вычисляется при регистрации
     *
     * @param bool|\Closure(): bool|string $condition Условие или сразу текст причины.
     */
    public function skip(bool|\Closure|string $condition = true, string $reason = ''): static
    {
        if (is_string($condition)) {
            $reason = $condition;
            $condition = true;
        }

        if ($condition instanceof \Closure) {
            $condition = (bool) $condition();
        }

        if ($condition) {
            $this->skipReason = $reason;
        }

        return $this;
    }

    public function skipReason(): ?string
    {
        return $this->skipReason;
    }

    /**
     * Конкретные запуски теста с названиями и аргументами.
     *
     * @return list<array{name: string, args: list<mixed>}>
     */
    public function cases(): array
    {
        $name = $this->scope->prefix($this->description);

        if ($this->datasets === []) {
            return [['name' => $name, 'args' => []]];
        }

        $cases = [];

        foreach ($this->datasets as $key => $row) {
            $args = is_array($row) ? array_values($row) : [$row];
            $label = is_string($key)
                ? 'with data set "' . $key . '"'
                : 'with (' . self::shorten(implode(', ', array_map(static fn (mixed $v): string => Expectation::export($v), $args))) . ')';

            $cases[] = ['name' => $name . ' ' . $label, 'args' => $args];
        }

        return $cases;
    }

    /**
     * Сокращает длинное название набора данных, не рвя UTF-8.
     */
    private static function shorten(string $label): string
    {
        return preg_match('/^.{41}/su', $label) === 1 ? preg_replace('/^(.{40}).*$/su', '$1…', $label) : $label;
    }
}
