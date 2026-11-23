<?php

declare(strict_types=1);

namespace Neo\Presentation\View;

use Neo\Presentation\View\Directives\BasicDirective;
use Neo\Presentation\View\Exceptions\CompilerException;
use Neo\Presentation\View\Transfers\CompilerTransfer;

/**
 * Превращает исходник шаблона в PHP-код.
 *
 * Понимает:
 *  - {{ expr }}        экранированный вывод   (разделители задаются в конфиге)
 *  - {!! expr !!}      сырой вывод
 *  - {{-- text --}}    комментарий (в результат не попадает)
 *  - @{{ ... }}        литерал «{{ ... }}» (для Vue и т.п.)
 *  - @@name            литерал «@name»
 *  - @directive(...)   зарегистрированные директивы; неизвестные @слова
 *                      (CSS @media, e-mail) остаются как есть.
 */
class Compiler
{
    # Список открытых директив, которые компилятор встретил,
    # И которые ещё не закрыты.
    #
    # Имеет вид:
    # [
    #     'directive' => IncludeDirective::class,
    #     'expression' => "'components.card'",
    #     'line' => 12,
    # ]
    public array $stack = [];

    /** @var array<class-string, BasicDirective> */
    private array $instances = [];

    /** @var array<string, BasicDirective> имя начала => директива */
    private array $starts = [];

    /** @var array<string, BasicDirective> имя конца => директива */
    private array $ends = [];

    /** @var array<string, list<BasicDirective>> имя ветки => директивы-владельцы */
    private array $branches = [];

    private string $open;
    private string $close;
    private string $rawOpen;
    private string $rawClose;
    private string $pattern;

    public function __construct(private CompilerTransfer $config)
    {
        [$this->open, $this->close] = $config->brackets;

        // {{ }} -> {!! !!}; [[ ]] -> [!! !!]
        $this->rawOpen = substr($this->open, 0, 1) . '!!';
        $this->rawClose = '!!' . substr($this->close, -1);

        $o = preg_quote($this->open, '~');
        $r = preg_quote($this->rawOpen, '~');

        // Порядок альтернатив важен: сначала самые длинные.
        $this->pattern = '~' . $o . '--|@' . $o . '|@' . $r . '|' . $r . '|' . $o
            . '|(?<![\w@])@@?[A-Za-z_]\w*~';

        foreach ($config->directives as $class) {
            $this->add($this->make($class));
        }
    }

    /**
     * Зарегистрировать готовый экземпляр директивы.
     */
    public function add(BasicDirective $directive): void
    {
        $this->instances[$directive::class] = $directive;
        $this->starts[$directive->directive] = $directive;

        if ($directive->directiveEnd !== null) {
            $this->ends[$directive->directiveEnd] = $directive;
        }

        foreach ($directive->branches as $branch) {
            $this->branches[$branch][] = $directive;
        }
    }

    /**
     * Экземпляр директивы по классу (нужен скомпилированным шаблонам в рантайме).
     */
    public function directive(string $class): BasicDirective
    {
        return $this->instances[$class]
            ?? throw new CompilerException("Директива {$class} не зарегистрирована.");
    }

    /**
     * Скомпилировать исходник в PHP.
     */
    public function compile(string $source): string
    {
        $this->stack = [];
        foreach ($this->instances as $directive) {
            $directive->reset();
        }

        $out = '';
        $pos = 0;
        $length = strlen($source);

        while ($pos < $length && preg_match($this->pattern, $source, $m, PREG_OFFSET_CAPTURE, $pos)) {
            [$token, $at] = $m[0];
            $out .= substr($source, $pos, $at - $pos);
            $pos = $at + strlen($token);

            if ($token === $this->open . '--') {
                $pos = $this->skipComment($source, $pos, $at);
            } elseif ($token === '@' . $this->open || $token === '@' . $this->rawOpen) {
                $out .= substr($token, 1);
            } elseif ($token === $this->open) {
                $out .= $this->compileEcho($source, $pos, $at, $this->close, '<?= $this->e(%s) ?>');
            } elseif ($token === $this->rawOpen) {
                $out .= $this->compileEcho($source, $pos, $at, $this->rawClose, '<?= %s ?>');
            } else {
                $out .= $this->compileDirective($token, $source, $pos, $at);
            }
        }

        if ($this->stack) {
            $frame = end($this->stack);
            $end = $this->instances[$frame['directive']]->directiveEnd;
            throw new CompilerException(
                "Директива " . $this->instances[$frame['directive']]->directive . " "
                . "(строка {$frame['line']}) не закрыта: ожидался {$end}."
            );
        }

        return $out . substr($source, $pos);
    }

    /**
     * Добавить директиву в стек.
     *
     * @param string $directive
     * @param string $expression
     * @param int $line
     * @return void
     */
    protected function pushDirective(
        string $directive,
        string $expression,
        int $line = 0
    ): void {
        $this->stack[] = [
            'directive' => $directive,
            'expression' => $expression,
            'line' => $line,
        ];
    }

    /**
     * Забрать директиву из стека.
     *
     * @return array|null
     */
    protected function popDirective(): ?array
    {
        return array_pop($this->stack);
    }

    /**
     * Создать директиву, подставив зависимости из dependency_injection
     * (аргументы конструктора — по порядку).
     */
    private function make(string $class): BasicDirective
    {
        $args = [];
        foreach ($this->config->dependencyInjection[$class] ?? [] as $implementation) {
            $args[] = new $implementation();
        }

        return new $class(...$args);
    }

    private function skipComment(string $source, int $pos, int $at): int
    {
        $end = strpos($source, '--' . $this->close, $pos);
        if ($end === false) {
            throw new CompilerException($this->at('Комментарий не закрыт', $source, $at));
        }

        return $end + strlen($this->close) + 2;
    }

    /**
     * {{ expr }} и {!! expr !!}. $pos сдвигается за закрывающий разделитель.
     */
    private function compileEcho(string $source, int &$pos, int $at, string $close, string $template): string
    {
        $end = strpos($source, $close, $pos);
        if ($end === false) {
            throw new CompilerException($this->at("Нет закрывающего «{$close}»", $source, $at));
        }

        $expression = trim(substr($source, $pos, $end - $pos));
        if ($expression === '') {
            throw new CompilerException($this->at('Пустое выражение вывода', $source, $at));
        }

        $pos = $end + strlen($close);
        $code = sprintf($template, $expression);

        // PHP съедает перевод строки после закрывающего тега — возвращаем его, как Blade.
        return ($source[$pos] ?? '') === "\n" ? $code . "\n" : $code;
    }

    private function compileDirective(string $token, string $source, int &$pos, int $at): string
    {
        // @@name -> литерал @name
        if (str_starts_with($token, '@@')) {
            return substr($token, 1);
        }

        $name = $token; // сопоставляем ровно с тем, что записано в директиве (вместе с «@»)
        $top = $this->stack ? end($this->stack)['directive'] : null;

        // 1. Внутренняя ветка (@else, @empty, @case) — только внутри своего блока.
        foreach ($this->branches[$name] ?? [] as $owner) {
            if ($top === $owner::class) {
                return $owner->branch($name, $this->readExpression($source, $pos) ?? '');
            }
        }

        // 2. Начало директивы.
        if (isset($this->starts[$name])) {
            return $this->compileStart($this->starts[$name], $source, $pos, $at);
        }

        // 3. Конец блока.
        if (isset($this->ends[$name])) {
            $directive = $this->ends[$name];
            $frame = $this->popDirective();

            if ($frame === null || $frame['directive'] !== $directive::class) {
                $expected = $frame === null
                    ? 'ни одна директива не открыта'
                    : 'ожидался ' . $this->instances[$frame['directive']]->directiveEnd
                        . ' (блок открыт на строке ' . $frame['line'] . ')';
                throw new CompilerException($this->at("Лишний {$name}, {$expected}", $source, $at));
            }

            return $directive->close() ?? '';
        }

        // 4. Неизвестное слово после «@» — оставляем текстом.
        return $token;
    }

    private function compileStart(BasicDirective $directive, string $source, int &$pos, int $at): string
    {
        $expression = $this->readExpression($source, $pos);

        if ($directive->rawBody) {
            // Строчная форма: @php($x = 1)
            if ($expression !== null || $directive->directiveEnd === null) {
                return $directive->render($expression ?? '');
            }

            $endTag = $directive->directiveEnd;
            if (!preg_match('~' . preg_quote($endTag, '~') . '(?!\w)~', $source, $m, PREG_OFFSET_CAPTURE, $pos)) {
                throw new CompilerException($this->at("Не найден {$endTag}", $source, $at));
            }

            $body = substr($source, $pos, $m[0][1] - $pos);
            $pos = $m[0][1] + strlen($endTag);

            return $directive->body($body);
        }

        $code = $directive->render($expression ?? '');

        if ($directive->directiveEnd !== null) {
            $this->pushDirective($directive::class, $expression ?? '', substr_count($source, "\n", 0, $at) + 1);
        }

        if ($directive->trimAfter) {
            $pos += strspn($source, " \t\r\n", $pos);
        }

        return $code;
    }

    /**
     * Читает «(...)» сразу после имени директивы с учётом вложенных скобок и строк.
     * Возвращает содержимое без внешних скобок или null, если скобок нет.
     * Допускаются пробелы между именем и скобкой: @if ($x).
     */
    private function readExpression(string $source, int &$pos): ?string
    {
        $i = $pos + strspn($source, " \t", $pos);
        $length = strlen($source);

        if ($i >= $length || $source[$i] !== '(') {
            return null;
        }

        $start = $i + 1;
        $depth = 0;
        $quote = null;

        for (; $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                $pos = $i + 1;

                return trim(substr($source, $start, $i - $start));
            }
        }

        throw new CompilerException($this->at('Не закрыта скобка в выражении директивы', $source, $pos));
    }

    private function at(string $message, string $source, int $offset): string
    {
        return $message . ' (строка ' . (substr_count($source, "\n", 0, $offset) + 1) . ').';
    }
}
