<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Directives;

/** @while (...) ... @endwhile */
class WhileDirective extends BasicDirective
{
    public string $directive = '@while';
    public ?string $directiveEnd = '@endwhile';

    public function render(string $expression): string
    {
        return $this->php("while ({$expression}):");
    }

    public function close(): ?string
    {
        return $this->php('endwhile;');
    }
}
