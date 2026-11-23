<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Directives;

/**
 * Базовая директива шаблонизатора.
 *
 * Компилятор сопоставляет имена ровно так, как они записаны: $directive = '@if', $directiveEnd = '@endif'.
 *
 * Виды директив:
 *  - строчная:      $directiveEnd = null            (@include, @csrf, @stack)
 *  - блочная:       $directiveEnd = '@endX'         (@if ... @endif)
 *  - с ветками:     $branches = ['@elseif', '@else']  (вызывается branch())
 *  - с сырым телом: $rawBody = true                 (@php ... @endphp, @verbatim)
 *
 * Директива живёт один раз на компилятор. Если ей нужно состояние между
 * render() и close() (вложенность!), хранит его в стеке $frames.
 */
abstract class BasicDirective
{
    public string $directive = '';
    public ?string $directiveEnd = null;

    /** Имена внутренних веток (@else, @case ...). */
    public array $branches = [];

    /** Тело до @end* компилятор не разбирает, а отдаёт в body() как есть. */
    public bool $rawBody = false;

    /** Съесть пробелы/переводы строк сразу после директивы (нужно для @switch). */
    public bool $trimAfter = false;

    /** Стек состояний вложенных блоков этой директивы. */
    protected array $frames = [];

    /** Счётчик для уникальных имён переменных в скомпилированном коде. */
    protected int $counter = 0;

    /**
     * Код начала директивы.
     */
    abstract public function render(string $expression): string;

    /**
     * Код внутренней ветки (@else, @empty, @case ...).
     */
    public function branch(string $name, string $expression): string
    {
        return '';
    }

    /**
     * Код закрытия блока.
     */
    public function close(): ?string
    {
        return null;
    }

    /**
     * Для $rawBody-директив: во что превратить сырое тело.
     */
    public function body(string $raw): string
    {
        return $raw;
    }

    /**
     * Компилятор вызывает перед каждым новым шаблоном: счётчик и стек начинаются заново,
     * поэтому один и тот же шаблон всегда компилируется в один и тот же код.
     */
    public function reset(): void
    {
        $this->frames = [];
        $this->counter = 0;
    }

    /**
     * Обернуть код в <?php ... ?>.
     */
    protected function php(string $code): string
    {
        return '<?php ' . $code . ' ?>';
    }

    /**
     * Вывод значения: <?= ... ?>.
     */
    protected function echo(string $code): string
    {
        return '<?= ' . $code . ' ?>';
    }

    /**
     * PHP-выражение, возвращающее живой экземпляр этой директивы в рантайме.
     * Нужно, когда директиве требуются зависимости (сессия, csrf), а скомпилированный
     * шаблон лежит в кэше и не может хранить их в себе.
     */
    protected function self(): string
    {
        return '$this->directive(' . var_export(static::class, true) . ')';
    }
}
