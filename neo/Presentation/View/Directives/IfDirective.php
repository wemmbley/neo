<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Directives;

/** @if / @elseif / @else / @endif */
class IfDirective extends BasicDirective
{
    public string $directive = '@if';
    public ?string $directiveEnd = '@endif';
    public array $branches = ['@elseif', '@else'];

    public function render(string $expression): string
    {
        return $this->php("if ({$expression}):");
    }

    public function branch(string $name, string $expression): string
    {
        return $name === '@else'
            ? $this->php('else:')
            : $this->php("elseif ({$expression}):");
    }

    public function close(): ?string
    {
        return $this->php('endif;');
    }
}
