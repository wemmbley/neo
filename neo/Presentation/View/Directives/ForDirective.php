<?php

declare(strict_types=1);

namespace Neo\Presentation\View\Directives;

/** @for (...) ... @endfor */
class ForDirective extends BasicDirective
{
    public string $directive = '@for';
    public ?string $directiveEnd = '@endfor';

    public function render(string $expression): string
    {
        return $this->php("for ({$expression}):");
    }

    public function close(): ?string
    {
        return $this->php('endfor;');
    }
}
