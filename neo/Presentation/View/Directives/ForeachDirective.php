<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Directives;

use Neo\Presentation\View\Exceptions\CompilerException;

/**
 * @foreach ($items as $item) ... @empty ... @endforeach
 *
 * Внутри доступен $loop (см. Neo\View\Loop). Порядок: @foreach, @empty, @endforeach.
 */
class ForeachDirective extends BasicDirective
{
    public string $directive = '@foreach';
    public ?string $directiveEnd = '@endforeach';
    public array $branches = ['@empty'];

    public function render(string $expression): string
    {
        if (!preg_match('/^(.+?)\s+as\s+(.+)$/s', $expression, $m)) {
            throw new CompilerException("@foreach: ожидалось «\$items as \$item», получено «{$expression}».");
        }

        $id = ++$this->counter;
        $this->frames[] = ['id' => $id, 'empty' => false];

        return $this->php(
            "\$__empty{$id} = true; \$__items{$id} = {$m[1]}; \$this->loopPush(\$__items{$id}); "
            . "foreach (\$__items{$id} as {$m[2]}): \$__empty{$id} = false; \$loop = \$this->loopTick();"
        );
    }

    public function branch(string $name, string $expression): string
    {
        $key = array_key_last($this->frames);
        $this->frames[$key]['empty'] = true;
        $id = $this->frames[$key]['id'];

        return $this->php(
            "endforeach; \$this->loopPop(); \$loop = \$this->loopCurrent(); if (\$__empty{$id}):"
        );
    }

    public function close(): ?string
    {
        $frame = array_pop($this->frames);

        return $frame['empty']
            ? $this->php('endif;')
            : $this->php('endforeach; $this->loopPop(); $loop = $this->loopCurrent();');
    }
}
